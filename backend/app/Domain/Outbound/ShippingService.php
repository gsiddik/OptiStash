<?php

namespace App\Domain\Outbound;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use App\Domain\Warehouse\TransportSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pack verification, shipments and dispatch. Packing checks every scanned product against what was picked for the order. A shipment
 * collects PACKED orders of one warehouse; the transport mode (OptiDeli, third-party API or manual) is taken from the tenant's setting
 * when the shipment is created. Dispatch records an outbox event with the manifest, which the integration of that mode delivers.
 */
final class ShippingService
{
    public function __construct(private readonly TenantStore $store) {}

    public function openPackage(string $orderId): object
    {
        return DB::transaction(function () use ($orderId) {
            $o = $this->store->find('outbound_orders', $orderId, true);
            abort_unless($o->status === 'PICKED', 422, 'Packing hanya untuk pesanan yang sudah selesai dipetik.');
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('PK-%s%06d', $period, DocumentNumbers::next($this->store->tenantId, 'PK', $period));
            DB::table('packages')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'order_id' => $orderId, 'packed_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('packing.opened', $id, ['number' => $number, 'order' => $o->number]);

            return $this->package($id);
        }, 3);
    }

    /** Adds scanned goods to an open package; the product (code or barcode) must be on the order with picked quantity still unpacked. */
    public function addItem(string $packageId, string $scan, int $qty): object
    {
        return DB::transaction(function () use ($packageId, $scan, $qty) {
            $pkg = $this->store->find('packages', $packageId, true);
            abort_unless($pkg->status === 'OPEN', 422, 'Kemasan sudah ditutup.');
            $scan = trim($scan);
            $line = DB::table('outbound_lines as l')->where('l.tenant_id', $this->store->tenantId)->where('l.order_id', $pkg->order_id)
                ->join('products as p', fn ($j) => $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id'))
                ->where(fn ($q) => $q->whereRaw('upper(p.code) = ?', [Str::upper($scan)])->orWhere('p.barcode', $scan))
                ->lockForUpdate()->first(['l.*']);
            abort_unless($line, 422, 'Produk yang dipindai tidak ada pada pesanan ini.');
            $left = $line->picked_qty - $line->packed_qty;
            abort_if($qty > $left, 422, "Jumlah melebihi sisa yang dipetik dan belum dikemas ({$left}).");
            DB::table('package_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'package_id' => $packageId, 'line_id' => $line->id, 'quantity' => $qty]);
            DB::table('outbound_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['packed_qty' => $line->packed_qty + $qty]);

            return $this->package($packageId);
        }, 3);
    }

    public function closePackage(string $packageId): object
    {
        return DB::transaction(function () use ($packageId) {
            $pkg = $this->store->find('packages', $packageId, true);
            abort_unless($pkg->status === 'OPEN', 422, 'Kemasan sudah ditutup.');
            abort_unless($this->store->query('package_lines')->where('package_id', $packageId)->exists(), 422, 'Kemasan kosong.');
            DB::table('packages')->where('tenant_id', $this->store->tenantId)->where('id', $packageId)->update(['status' => 'CLOSED', 'updated_at' => now()]);
            $this->store->event('packing.closed', $packageId, ['number' => $pkg->number]);

            return $this->package($packageId);
        }, 3);
    }

    /**
     * Finishes packing: every picked unit must be in a closed package. When the order is short (picked less than ordered) the shortage
     * must be accepted as a partial shipment with a reason, by someone who may approve it.
     */
    public function completePacking(string $orderId, ?string $partialReason, bool $mayApprovePartial): object
    {
        return DB::transaction(function () use ($orderId, $partialReason, $mayApprovePartial) {
            $o = $this->store->find('outbound_orders', $orderId, true);
            abort_unless($o->status === 'PICKED', 422, 'Packing hanya untuk pesanan yang sudah selesai dipetik.');
            abort_if($this->store->query('packages')->where('order_id', $orderId)->where('status', 'OPEN')->exists(), 422, 'Masih ada kemasan yang belum ditutup.');
            $lines = $this->store->query('outbound_lines')->where('order_id', $orderId)->get();
            abort_unless($lines->sum('picked_qty') > 0, 422, 'Tidak ada barang yang dipetik; batalkan pesanan atau alokasikan ulang.');
            abort_if($lines->contains(fn ($l) => $l->packed_qty < $l->picked_qty), 422, 'Masih ada barang yang dipetik tetapi belum dikemas.');
            $short = $lines->contains(fn ($l) => $l->picked_qty < $l->ordered_qty);
            if ($short) {
                abort_unless($mayApprovePartial, 403, 'Pengiriman parsial memerlukan persetujuan (izin wave.approve).');
                abort_if(mb_strlen(trim((string) $partialReason)) < 5, 422, 'Pengiriman parsial memerlukan alasan (minimal 5 karakter).');
            }
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->where('id', $orderId)->update(['status' => 'PACKED', 'updated_at' => now()]);
            $this->store->event('packing.completed', $orderId, ['number' => $o->number, 'partial' => $short, 'reason' => $short ? trim((string) $partialReason) : null]);

            return (new OrderService($this->store))->one($orderId);
        }, 3);
    }

    public function package(string $id): object
    {
        $p = $this->store->find('packages', $id);
        $p->lines = DB::table('package_lines as pl')->where('pl.tenant_id', $this->store->tenantId)->where('pl.package_id', $id)
            ->join('outbound_lines as l', fn ($j) => $j->on('l.id', '=', 'pl.line_id')->on('l.tenant_id', '=', 'pl.tenant_id'))
            ->join('products as pr', fn ($j) => $j->on('pr.id', '=', 'l.product_id')->on('pr.tenant_id', '=', 'l.tenant_id'))
            ->get(['pr.code as product_code', 'pr.name as product_name', 'pl.quantity'])->all();

        return $p;
    }

    public function createShipment(string $warehouseId, ?string $carrierId, ?string $plate, ?string $driver): object
    {
        return DB::transaction(function () use ($warehouseId, $carrierId, $plate, $driver) {
            $w = $this->store->find('warehouses', $warehouseId);
            abort_unless($w->active, 422, 'Gudang tidak aktif.');
            if ($carrierId !== null) {
                $c = $this->store->query('partners')->where('id', $carrierId)->first();
                abort_unless($c && $c->type === 'CARRIER' && $c->active, 422, 'Ekspedisi tidak ditemukan atau tidak aktif.');
            }
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('SH-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'SH', $period));
            $mode = TransportSettings::get($this->store->tenantId)['mode'];
            DB::table('shipments')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'warehouse_id' => $warehouseId, 'mode' => $mode, 'carrier_id' => $carrierId,
                'truck_plate' => $plate, 'driver_name' => $driver, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('shipment.created', $id, ['number' => $number, 'mode' => $mode]);

            return $this->shipment($id);
        }, 3);
    }

    public function addOrder(string $shipmentId, string $orderId): object
    {
        return DB::transaction(function () use ($shipmentId, $orderId) {
            $s = $this->store->find('shipments', $shipmentId, true);
            abort_unless($s->status === 'LOADING', 422, 'Pengiriman sudah dikirim atau dibatalkan.');
            $o = $this->store->find('outbound_orders', $orderId, true);
            abort_unless($o->status === 'PACKED' && $o->warehouse_id === $s->warehouse_id, 422, 'Hanya pesanan PACKED dari gudang yang sama yang dapat dimuat.');
            abort_if($this->store->query('shipment_orders')->where('order_id', $orderId)->exists(), 422, 'Pesanan sudah ada di pengiriman lain.');
            DB::table('shipment_orders')->insert(['tenant_id' => $this->store->tenantId, 'shipment_id' => $shipmentId, 'order_id' => $orderId]);
            $this->store->event('shipment.order_added', $shipmentId, ['order' => $o->number]);

            return $this->shipment($shipmentId);
        }, 3);
    }

    public function removeOrder(string $shipmentId, string $orderId): object
    {
        return DB::transaction(function () use ($shipmentId, $orderId) {
            $s = $this->store->find('shipments', $shipmentId, true);
            abort_unless($s->status === 'LOADING', 422, 'Pengiriman sudah dikirim atau dibatalkan.');
            $this->store->query('shipment_orders')->where('shipment_id', $shipmentId)->where('order_id', $orderId)->delete();

            return $this->shipment($shipmentId);
        }, 3);
    }

    /** Dispatches the shipment: its orders become DISPATCHED and the manifest goes out through the outbox for the transport mode. */
    public function dispatch(string $shipmentId): object
    {
        return DB::transaction(function () use ($shipmentId) {
            $s = $this->store->find('shipments', $shipmentId, true);
            abort_unless($s->status === 'LOADING', 422, 'Pengiriman sudah dikirim atau dibatalkan.');
            $orderIds = $this->store->query('shipment_orders')->where('shipment_id', $shipmentId)->pluck('order_id')->all();
            abort_if($orderIds === [], 422, 'Pengiriman belum memuat pesanan.');
            abort_if($s->carrier_id === null && ($s->truck_plate === null || $s->truck_plate === ''), 422, 'Isi ekspedisi atau plat kendaraan sebelum dikirim.');
            DB::table('outbound_orders')->where('tenant_id', $this->store->tenantId)->whereIn('id', $orderIds)->where('status', 'PACKED')->update(['status' => 'DISPATCHED', 'updated_at' => now()]);
            DB::table('shipments')->where('tenant_id', $this->store->tenantId)->where('id', $shipmentId)->update(['status' => 'DISPATCHED', 'dispatched_at' => now(), 'updated_at' => now()]);
            $manifest = $this->manifest($shipmentId);
            $this->store->event('shipment.dispatched', $shipmentId, ['number' => $s->number, 'mode' => $s->mode, 'manifest' => $manifest, 'pickup' => $this->pickup($s, $orderIds)]);

            return $this->shipment($shipmentId);
        }, 3);
    }

    /**
     * What a carrier needs to collect the goods (the OptiDeli contract, docs/architecture/INTEGRATION_ORCHESTRATOR.md): the warehouse as a
     * place (never its internal zones, aisles or bins) and the goods to take, summed per product in the base unit of measure.
     */
    private function pickup(object $s, array $orderIds): array
    {
        $w = $this->store->find('warehouses', $s->warehouse_id);
        $items = DB::table('outbound_lines as l')->where('l.tenant_id', $this->store->tenantId)->whereIn('l.order_id', $orderIds)->where('l.packed_qty', '>', 0)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id'))
            ->join('uoms as u', fn ($j) => $j->on('u.id', '=', 'p.base_uom_id')->on('u.tenant_id', '=', 'p.tenant_id'))
            ->groupBy('p.code', 'p.name', 'u.code')->orderBy('p.code')
            ->get(['p.code as product_code', 'p.name as product_name', 'u.code as uom', DB::raw('sum(l.packed_qty) as quantity')]);

        return [
            'shipment_number' => $s->number,
            'warehouse' => ['code' => $w->code, 'name' => $w->name, 'address' => $w->address, 'city' => $w->city],
            'items' => $items->map(fn ($i) => ['product_code' => $i->product_code, 'product_name' => $i->product_name, 'quantity' => (int) $i->quantity, 'uom' => $i->uom])->all(),
        ];
    }

    public function cancelShipment(string $shipmentId, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($shipmentId, $reason) {
            $s = $this->store->find('shipments', $shipmentId, true);
            abort_unless($s->status === 'LOADING', 422, 'Hanya pengiriman yang masih dimuat yang dapat dibatalkan.');
            $this->store->query('shipment_orders')->where('shipment_id', $shipmentId)->delete();
            DB::table('shipments')->where('tenant_id', $this->store->tenantId)->where('id', $shipmentId)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->store->event('shipment.cancelled', $shipmentId, ['number' => $s->number, 'reason' => trim($reason)]);

            return $this->shipment($shipmentId);
        }, 3);
    }

    /** The manifest: what leaves, for whom, in how many packages. The same structure is what the transport integration receives. */
    public function manifest(string $shipmentId): array
    {
        $s = $this->store->find('shipments', $shipmentId);
        $orders = DB::table('shipment_orders as so')->where('so.tenant_id', $this->store->tenantId)->where('so.shipment_id', $shipmentId)
            ->join('outbound_orders as o', fn ($j) => $j->on('o.id', '=', 'so.order_id')->on('o.tenant_id', '=', 'so.tenant_id'))
            ->join('partners as c', fn ($j) => $j->on('c.id', '=', 'o.customer_id')->on('c.tenant_id', '=', 'o.tenant_id'))
            ->orderBy('o.number')->get(['o.id', 'o.number', 'o.reference', 'o.ship_to', 'o.required_date', 'c.code as customer_code', 'c.name as customer_name']);
        $out = [];
        $weight = 0;
        foreach ($orders as $o) {
            $lines = DB::table('outbound_lines as l')->where('l.tenant_id', $this->store->tenantId)->where('l.order_id', $o->id)->where('l.packed_qty', '>', 0)
                ->join('products as p', fn ($j) => $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id'))->orderBy('l.line_no')
                ->get(['p.code as product_code', 'p.name as product_name', 'p.weight_g', 'l.ordered_qty', 'l.packed_qty']);
            $weight += (int) $lines->sum(fn ($l) => ($l->weight_g ?? 0) * $l->packed_qty);
            $out[] = ['order' => $o->number, 'reference' => $o->reference, 'customer' => $o->customer_code.' '.$o->customer_name, 'ship_to' => $o->ship_to, 'required_date' => $o->required_date,
                'packages' => $this->store->query('packages')->where('order_id', $o->id)->where('status', 'CLOSED')->count(),
                'lines' => $lines->map(fn ($l) => ['product' => $l->product_code, 'name' => $l->product_name, 'ordered' => $l->ordered_qty, 'shipped' => $l->packed_qty])->all()];
        }

        return ['shipment' => $s->number, 'mode' => $s->mode, 'carrier_id' => $s->carrier_id, 'truck_plate' => $s->truck_plate, 'driver' => $s->driver_name,
            'warehouse_id' => $s->warehouse_id, 'orders' => $out, 'total_packages' => array_sum(array_column($out, 'packages')), 'total_weight_g' => $weight];
    }

    public function shipment(string $id): object
    {
        $s = $this->store->find('shipments', $id);
        $s->orders = DB::table('shipment_orders as so')->where('so.tenant_id', $this->store->tenantId)->where('so.shipment_id', $id)
            ->join('outbound_orders as o', fn ($j) => $j->on('o.id', '=', 'so.order_id')->on('o.tenant_id', '=', 'so.tenant_id'))->orderBy('o.number')->get(['o.id', 'o.number', 'o.status'])->all();

        return $s;
    }

    /** The latest 100 shipments with their orders and the carrier's status history (what the console shows on one card). */
    public function shipments(?string $status): array
    {
        $rows = $this->store->query('shipments')->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->limit(100)->get();
        $ids = $rows->pluck('id')->all();
        $orders = DB::table('shipment_orders as so')->where('so.tenant_id', $this->store->tenantId)->whereIn('so.shipment_id', $ids)
            ->join('outbound_orders as o', fn ($j) => $j->on('o.id', '=', 'so.order_id')->on('o.tenant_id', '=', 'so.tenant_id'))
            ->orderBy('o.number')->get(['so.shipment_id', 'o.id', 'o.number', 'o.status'])->groupBy('shipment_id');
        $events = $this->store->query('shipment_carrier_events')->whereIn('shipment_id', $ids)->orderByDesc('occurred_at')->get(['shipment_id', 'status', 'reason', 'occurred_at'])->groupBy('shipment_id');
        foreach ($rows as $s) {
            $s->orders = ($orders[$s->id] ?? collect())->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status])->values()->all();
            $s->carrier_events = ($events[$s->id] ?? collect())->take(10)->values()->all();
        }

        return $rows->all();
    }
}
