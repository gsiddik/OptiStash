<?php

namespace App\Domain\Outbound;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outbound orders and stock allocation. DRAFT -> RELEASED -> ALLOCATED (every line fully or partly reserved) -> PICKING -> PICKED ->
 * PACKED -> DISPATCHED; DRAFT, RELEASED and ALLOCATED can be CANCELLED (reservations are given back). Allocation reserves whole or
 * partial stock units following each product's rotation policy: FEFO (earliest expiry), FIFO (oldest receipt) or LIFO (newest receipt).
 * Locked, quarantined and not-yet-stored stock, and stock in a blocked location, is never allocated.
 */
final class OrderService
{
    public function __construct(private readonly TenantStore $store) {}

    /** @param array{customer_id:string,warehouse_id:string,required_date:string,reference?:?string,ship_to:string,notes?:?string,lines:list<array{product_id:string,ordered_qty:int}>} $data */
    public function create(array $data): object
    {
        return DB::transaction(function () use ($data) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $customer = $this->store->query('partners')->where('id', $data['customer_id'])->first();
            abort_unless($customer && $customer->type === 'CUSTOMER' && $customer->active, 422, 'Pelanggan tidak ditemukan atau tidak aktif.');
            $warehouse = $this->store->query('warehouses')->where('id', $data['warehouse_id'])->first();
            abort_unless($warehouse && $warehouse->active, 422, 'Gudang tidak ditemukan atau tidak aktif.');
            $ids = array_column($data['lines'], 'product_id');
            abort_if(count($ids) !== count(array_unique($ids)), 422, 'Satu produk hanya boleh muncul sekali dalam satu pesanan.');
            abort_unless($this->store->query('products')->whereIn('id', $ids)->where('active', true)->count() === count($ids), 422, 'Ada produk yang tidak ditemukan atau tidak aktif.');
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('SO-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'SO', $period));
            DB::table('outbound_orders')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'customer_id' => $data['customer_id'], 'warehouse_id' => $data['warehouse_id'],
                'status' => 'DRAFT', 'required_date' => $data['required_date'], 'reference' => $data['reference'] ?? null, 'ship_to' => $data['ship_to'], 'notes' => $data['notes'] ?? null,
                'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('outbound_lines')->insert(array_map(fn ($l, $i) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'order_id' => $id, 'line_no' => $i + 1, 'product_id' => $l['product_id'], 'ordered_qty' => $l['ordered_qty'],
            ], $data['lines'], array_keys($data['lines'])));
            $this->store->event('outbound.created', $id, ['number' => $number, 'lines' => count($data['lines'])]);

            return $this->one($id);
        }, 3);
    }

    public function release(string $id): object
    {
        return $this->move($id, ['DRAFT'], 'RELEASED', 'outbound.released');
    }

    private function move(string $id, array $from, string $to, string $event, array $extra = []): object
    {
        return DB::transaction(function () use ($id, $from, $to, $event, $extra) {
            $o = $this->store->find('outbound_orders', $id, true);
            abort_unless(in_array($o->status, $from, true), 422, "Pesanan berstatus {$o->status}; tindakan ini hanya untuk ".implode('/', $from).'.');
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => $to, 'updated_at' => now()]);
            $this->store->event($event, $id, ['number' => $o->number, 'from' => $o->status, 'to' => $to] + $extra);

            return $this->one($id);
        }, 3);
    }

    /** Reserves stock for every line of a RELEASED order. A short line stays short (partly allocated); the order is ALLOCATED when anything was reserved. */
    public function allocate(string $id): object
    {
        return DB::transaction(function () use ($id) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $o = $this->store->find('outbound_orders', $id, true);
            abort_unless($o->status === 'RELEASED', 422, 'Hanya pesanan RELEASED yang dapat dialokasikan.');
            $blocked = $this->blockedNodes($o->warehouse_id);
            $total = 0;
            foreach ($this->store->query('outbound_lines')->where('order_id', $id)->orderBy('line_no')->get() as $line) {
                $product = $this->store->find('products', $line->product_id);
                $need = $line->ordered_qty;
                $order = match ($product->rotation_policy) {
                    'FEFO' => 'expiry_date asc nulls last, received_at asc',
                    'LIFO' => 'received_at desc',
                    default => 'received_at asc',
                };
                $units = $this->store->query('stock_units')->where('warehouse_id', $o->warehouse_id)->where('product_id', $line->product_id)
                    ->where('status', 'AVAILABLE')->where('locked', false)->whereNotNull('location_id')->whereRaw('quantity > reserved_qty')
                    ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
                    ->orderByRaw($order)->lockForUpdate()->get();
                foreach ($units as $u) {
                    if ($need <= 0) {
                        break;
                    }
                    if (isset($blocked[$u->location_id])) {
                        continue;
                    }
                    $take = min($need, $u->quantity - $u->reserved_qty);
                    DB::table('allocations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'line_id' => $line->id, 'stock_unit_id' => $u->id, 'quantity' => $take, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $u->id)->update(['reserved_qty' => $u->reserved_qty + $take, 'updated_at' => now()]);
                    $need -= $take;
                    $total += $take;
                }
                DB::table('outbound_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['allocated_qty' => $line->ordered_qty - $need]);
            }
            abort_if($total === 0, 422, 'Tidak ada stok tersedia untuk pesanan ini.');
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'ALLOCATED', 'updated_at' => now()]);
            $this->store->event('outbound.allocated', $id, ['number' => $o->number, 'allocated' => $total]);

            return $this->one($id);
        }, 3);
    }

    /** @return array<string, true> ids of locations that are blocked or inactive, or sit below such a location */
    private function blockedNodes(string $warehouseId): array
    {
        $nodes = $this->store->query('storage_nodes')->where('warehouse_id', $warehouseId)->get()->keyBy('id');
        $bad = [];
        foreach ($nodes as $n) {
            for ($p = $n; $p !== null; $p = $p->parent_id ? ($nodes[$p->parent_id] ?? null) : null) {
                if ($p->blocked || ! $p->active) {
                    $bad[$n->id] = true;
                    break;
                }
            }
        }

        return $bad;
    }

    /** Gives back every reservation that has not been picked. */
    public function releaseReservations(string $orderId): void
    {
        $rows = DB::table('allocations as a')->where('a.tenant_id', $this->store->tenantId)->where('a.status', 'RESERVED')
            ->join('outbound_lines as l', fn ($j) => $j->on('l.id', '=', 'a.line_id')->on('l.tenant_id', '=', 'a.tenant_id'))->where('l.order_id', $orderId)->get(['a.id', 'a.stock_unit_id', 'a.quantity']);
        foreach ($rows as $a) {
            DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $a->stock_unit_id)->decrement('reserved_qty', $a->quantity);
            DB::table('allocations')->where('tenant_id', $this->store->tenantId)->where('id', $a->id)->update(['status' => 'RELEASED', 'updated_at' => now()]);
        }
    }

    public function cancel(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan pembatalan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $o = $this->store->find('outbound_orders', $id, true);
            abort_unless(in_array($o->status, ['DRAFT', 'RELEASED', 'ALLOCATED'], true), 422, 'Pesanan yang sudah masuk picking tidak dapat dibatalkan.');
            $this->releaseReservations($id);
            $this->store->query('outbound_lines')->where('order_id', $id)->update(['allocated_qty' => 0]);
            $this->move($id, ['DRAFT', 'RELEASED', 'ALLOCATED'], 'CANCELLED', 'outbound.cancelled', ['reason' => trim($reason)]);

            return $this->one($id);
        }, 3);
    }

    public function one(string $id): object
    {
        $o = $this->store->find('outbound_orders', $id);
        $o->customer_name = $this->store->query('partners')->where('id', $o->customer_id)->value('name');
        $o->lines = DB::table('outbound_lines as l')->where('l.tenant_id', $this->store->tenantId)->where('l.order_id', $id)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id'))->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'l.product_id', 'p.code as product_code', 'p.name as product_name', 'l.ordered_qty', 'l.allocated_qty', 'l.picked_qty', 'l.packed_qty'])->all();

        return $o;
    }

    public function list(?string $status, int $page, int $perPage = 50): array
    {
        $q = DB::table('outbound_orders as o')->where('o.tenant_id', $this->store->tenantId)->when($status, fn ($q) => $q->where('o.status', $status))
            ->join('partners as c', fn ($j) => $j->on('c.id', '=', 'o.customer_id')->on('c.tenant_id', '=', 'o.tenant_id'));
        $total = (clone $q)->count();

        return ['data' => $q->orderByDesc('o.created_at')->forPage($page, $perPage)->get(['o.id', 'o.number', 'o.status', 'o.required_date', 'o.reference', 'c.name as customer_name']),
            'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }
}
