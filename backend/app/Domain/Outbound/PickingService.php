<?php

namespace App\Domain\Outbound;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Waves and picking. A wave takes ALLOCATED orders of one warehouse and turns their reservations into pick tasks:
 *  - WAVE: one pick path for all orders, tasks sorted by location code;
 *  - BATCH: tasks grouped by product (then location) so a picker takes one product for many orders in one go;
 *  - CLUSTER: like WAVE, and every order gets a tote slot so several orders are picked in one tour.
 * Picking scans the LPN of the unit the task points at. Picked stock leaves the warehouse stock (ledger type ISSUE).
 */
final class PickingService
{
    public const TYPES = ['WAVE', 'BATCH', 'CLUSTER'];

    public function __construct(private readonly TenantStore $store) {}

    /** @param list<string> $orderIds */
    public function createWave(string $type, array $orderIds): object
    {
        abort_unless(in_array($type, self::TYPES, true), 422, 'Jenis wave tidak dikenal.');
        abort_if($orderIds === [] || count($orderIds) > 100 || count($orderIds) !== count(array_unique($orderIds)), 422, 'Pilih 1 sampai 100 pesanan berbeda.');

        return DB::transaction(function () use ($type, $orderIds) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $orders = $this->store->query('outbound_orders')->whereIn('id', $orderIds)->lockForUpdate()->get();
            abort_unless($orders->count() === count($orderIds), 404);
            abort_if($orders->pluck('warehouse_id')->unique()->count() > 1, 422, 'Satu wave hanya untuk satu gudang.');
            abort_if($orders->contains(fn ($o) => $o->status !== 'ALLOCATED'), 422, 'Semua pesanan dalam wave harus berstatus ALLOCATED.');

            $rows = DB::table('allocations as a')->where('a.tenant_id', $this->store->tenantId)->where('a.status', 'RESERVED')
                ->join('outbound_lines as l', fn ($j) => $j->on('l.id', '=', 'a.line_id')->on('l.tenant_id', '=', 'a.tenant_id'))->whereIn('l.order_id', $orderIds)
                ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 'a.stock_unit_id')->on('u.tenant_id', '=', 'a.tenant_id'))
                ->join('storage_nodes as n', fn ($j) => $j->on('n.id', '=', 'u.location_id')->on('n.tenant_id', '=', 'u.tenant_id'))
                ->join('products as p', fn ($j) => $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id'))
                ->get(['a.id as allocation_id', 'a.quantity', 'u.id as unit_id', 'u.location_id', 'n.code as location_code', 'l.product_id', 'p.code as product_code', 'l.order_id']);
            abort_if($rows->isEmpty(), 422, 'Tidak ada alokasi untuk dipetik.');
            $rows = $type === 'BATCH' ? $rows->sortBy(fn ($r) => $r->product_code.'|'.$r->location_code)->values() : $rows->sortBy('location_code')->values();
            $slots = $type === 'CLUSTER' ? array_flip($orderIds) : [];
            abort_if($type === 'CLUSTER' && count($orderIds) > 12, 422, 'Satu cluster maksimal 12 pesanan (jumlah slot tote).');

            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('WV-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'WV', $period));
            DB::table('waves')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'warehouse_id' => $orders[0]->warehouse_id, 'type' => $type, 'status' => 'RELEASED', 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pick_tasks')->insert($rows->map(fn ($r, $i) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'wave_id' => $id, 'allocation_id' => $r->allocation_id, 'stock_unit_id' => $r->unit_id,
                'location_id' => $r->location_id, 'product_id' => $r->product_id, 'order_id' => $r->order_id, 'sequence' => $i + 1,
                'slot' => $type === 'CLUSTER' ? $slots[$r->order_id] + 1 : null, 'quantity' => $r->quantity, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->whereIn('id', $orderIds)->update(['status' => 'PICKING', 'updated_at' => now()]);
            $this->store->event('wave.released', $id, ['number' => $number, 'type' => $type, 'orders' => count($orderIds), 'tasks' => $rows->count()]);

            return $this->wave($id);
        }, 3);
    }

    public function wave(string $id): object
    {
        $w = $this->store->find('waves', $id);
        $w->tasks = DB::table('pick_tasks as t')->where('t.tenant_id', $this->store->tenantId)->where('t.wave_id', $id)
            ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 't.stock_unit_id')->on('u.tenant_id', '=', 't.tenant_id'))
            ->join('storage_nodes as n', fn ($j) => $j->on('n.id', '=', 't.location_id')->on('n.tenant_id', '=', 't.tenant_id'))
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 't.product_id')->on('p.tenant_id', '=', 't.tenant_id'))
            ->join('outbound_orders as o', fn ($j) => $j->on('o.id', '=', 't.order_id')->on('o.tenant_id', '=', 't.tenant_id'))
            ->orderBy('t.sequence')
            ->get(['t.id', 't.sequence', 't.slot', 't.quantity', 't.picked_qty', 't.status', 't.short_reason', 'u.lpn', 'u.lot_no', 'u.expiry_date', 'n.code as location_code', 'p.code as product_code', 'p.name as product_name', 'o.number as order_number'])->all();

        return $w;
    }

    public function waves(?string $status): array
    {
        return $this->store->query('waves')->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->limit(100)->get()->all();
    }

    /**
     * Confirms a pick. The scanned LPN must be the one the task points at; $qty may be less than the task quantity only with a reason
     * (short pick: the missing quantity is given back and the line stays short for the order's partial-shipment decision).
     */
    public function pick(string $taskId, string $lpn, int $qty, ?string $shortReason): object
    {
        return DB::transaction(function () use ($taskId, $lpn, $qty, $shortReason) {
            $task = $this->store->find('pick_tasks', $taskId, true);
            abort_unless($task->status === 'OPEN', 422, 'Tugas pick sudah selesai.');
            $unit = $this->store->find('stock_units', $task->stock_unit_id, true);
            abort_unless(hash_equals(Str::upper($unit->lpn), Str::upper(trim($lpn))), 422, 'LPN yang dipindai bukan LPN pada tugas ini.');
            abort_if($unit->locked || $unit->status !== 'AVAILABLE', 422, 'Stok pada LPN ini terkunci atau tidak tersedia.');
            abort_if($qty < 0 || $qty > $task->quantity, 422, 'Jumlah pick melebihi tugas.');
            abort_if($qty < $task->quantity && mb_strlen(trim((string) $shortReason)) < 5, 422, 'Pick kurang dari tugas memerlukan alasan (minimal 5 karakter).');
            abort_if($qty > $unit->quantity, 409, 'Stok fisik pada LPN kurang dari jumlah pick; lakukan cycle count.');

            DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $unit->id)->update([
                'quantity' => $unit->quantity - $qty, 'reserved_qty' => $unit->reserved_qty - $task->quantity, 'status' => $unit->quantity - $qty === 0 ? 'DEPLETED' : 'AVAILABLE', 'updated_at' => now(),
            ]);
            $alloc = $this->store->find('allocations', $task->allocation_id, true);
            DB::table('allocations')->where('tenant_id', $this->store->tenantId)->where('id', $alloc->id)->update(['status' => 'PICKED', 'quantity' => $qty, 'updated_at' => now()]);
            DB::table('pick_tasks')->where('tenant_id', $this->store->tenantId)->where('id', $taskId)->update([
                'status' => $qty < $task->quantity ? 'SHORT' : 'DONE', 'picked_qty' => $qty, 'short_reason' => $qty < $task->quantity ? trim((string) $shortReason) : null,
                'picked_by' => $this->store->actorId, 'picked_at' => now(), 'updated_at' => now(),
            ]);
            $line = $this->store->find('outbound_lines', $alloc->line_id, true);
            DB::table('outbound_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['picked_qty' => $line->picked_qty + $qty, 'allocated_qty' => $line->allocated_qty - ($task->quantity - $qty)]);
            if ($qty > 0) {
                DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stock_unit_id' => $unit->id, 'type' => 'ISSUE', 'quantity' => -$qty,
                    'from_location_id' => $unit->location_id, 'reason' => null, 'actor_id' => $this->store->actorId, 'created_at' => now()]);
            }
            $this->store->event('picking.picked', $taskId, ['lpn' => $unit->lpn, 'quantity' => $qty, 'short' => $qty < $task->quantity]);
            $this->advance($task->wave_id, $task->order_id);

            return $this->store->find('pick_tasks', $taskId);
        }, 3);
    }

    /** An order is PICKED when none of its tasks is open; a wave is COMPLETED when all its tasks are done. */
    private function advance(string $waveId, string $orderId): void
    {
        if (! $this->store->query('pick_tasks')->where('order_id', $orderId)->where('status', 'OPEN')->exists()) {
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->where('id', $orderId)->where('status', 'PICKING')->update(['status' => 'PICKED', 'updated_at' => now()]);
        }
        if (! $this->store->query('pick_tasks')->where('wave_id', $waveId)->where('status', 'OPEN')->exists()) {
            DB::table('waves')->where('tenant_id', $this->store->tenantId)->where('id', $waveId)->update(['status' => 'COMPLETED', 'updated_at' => now()]);
        }
    }
}
