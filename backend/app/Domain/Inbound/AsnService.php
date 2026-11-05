<?php

namespace App\Domain\Inbound;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Advance shipping notices. DRAFT -> SUBMITTED -> CONFIRMED (by warehouse staff with asn.approve) -> ARRIVED (gate-in) -> RECEIVING
 * -> RECEIVED; DRAFT, SUBMITTED and CONFIRMED can be CANCELLED. A supplier's own portal user creates and submits ASNs for that supplier
 * only; warehouse staff can do the same on a supplier's behalf (source INTERNAL).
 */
final class AsnService
{
    public const EDITABLE = ['DRAFT'];

    public function __construct(private readonly TenantStore $store) {}

    /** @param array{supplier_id:string,warehouse_id:string,expected_date:string,reference?:?string,truck_plate?:?string,notes?:?string,lines:list<array{product_id:string,expected_qty:int}>} $data */
    public function create(array $data, string $source): object
    {
        return DB::transaction(function () use ($data, $source) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $this->assertParties($data);
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('ASN-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'ASN', $period));
            DB::table('asns')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'supplier_id' => $data['supplier_id'], 'warehouse_id' => $data['warehouse_id'],
                'status' => 'DRAFT', 'source' => $source, 'expected_date' => $data['expected_date'], 'reference' => $data['reference'] ?? null,
                'truck_plate' => $data['truck_plate'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeLines($id, $data['lines']);
            $this->store->event('asn.created', $id, ['number' => $number, 'source' => $source, 'lines' => count($data['lines'])]);

            return $this->one($id);
        }, 3);
    }

    /** Replaces the header and lines of a draft. */
    public function update(string $id, array $data): object
    {
        return DB::transaction(function () use ($id, $data) {
            $asn = $this->store->find('asns', $id, true);
            abort_unless(in_array($asn->status, self::EDITABLE, true), 422, 'ASN hanya dapat diubah selama masih draf.');
            $this->assertParties($data);
            DB::table('asns')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update([
                'supplier_id' => $data['supplier_id'], 'warehouse_id' => $data['warehouse_id'], 'expected_date' => $data['expected_date'],
                'reference' => $data['reference'] ?? null, 'truck_plate' => $data['truck_plate'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_at' => now(),
            ]);
            DB::table('asn_lines')->where('tenant_id', $this->store->tenantId)->where('asn_id', $id)->delete();
            $this->writeLines($id, $data['lines']);
            $this->store->event('asn.updated', $id, ['number' => $asn->number]);

            return $this->one($id);
        }, 3);
    }

    private function assertParties(array $data): void
    {
        $supplier = $this->store->query('partners')->where('id', $data['supplier_id'])->first();
        abort_unless($supplier && $supplier->type === 'SUPPLIER' && $supplier->active, 422, 'Pemasok tidak ditemukan atau tidak aktif.');
        $warehouse = $this->store->query('warehouses')->where('id', $data['warehouse_id'])->first();
        abort_unless($warehouse && $warehouse->active, 422, 'Gudang tujuan tidak ditemukan atau tidak aktif.');
        abort_if($data['expected_date'] < now()->toDateString(), 422, 'Tanggal kedatangan tidak boleh di masa lalu.');
        abort_if($data['lines'] === [], 422, 'ASN memerlukan sedikitnya satu baris.');
        $ids = array_column($data['lines'], 'product_id');
        abort_if(count($ids) !== count(array_unique($ids)), 422, 'Satu produk hanya boleh muncul sekali dalam satu ASN.');
        $active = $this->store->query('products')->whereIn('id', $ids)->where('active', true)->count();
        abort_unless($active === count($ids), 422, 'Ada produk yang tidak ditemukan atau tidak aktif.');
    }

    private function writeLines(string $asnId, array $lines): void
    {
        DB::table('asn_lines')->insert(array_map(fn ($l, $i) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'asn_id' => $asnId, 'line_no' => $i + 1,
            'product_id' => $l['product_id'], 'expected_qty' => $l['expected_qty'],
        ], $lines, array_keys($lines)));
    }

    private function move(string $id, array $from, string $to, string $event, array $extra = []): object
    {
        return DB::transaction(function () use ($id, $from, $to, $event, $extra) {
            $asn = $this->store->find('asns', $id, true);
            abort_unless(in_array($asn->status, $from, true), 422, "ASN berstatus {$asn->status}; tindakan ini hanya untuk ".implode('/', $from).'.');
            DB::table('asns')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => $to, 'updated_at' => now()]);
            $this->store->event($event, $id, ['number' => $asn->number, 'from' => $asn->status, 'to' => $to] + $extra);

            return $this->one($id);
        }, 3);
    }

    public function submit(string $id): object
    {
        $this->store->find('asns', $id);
        abort_unless($this->store->query('asn_lines')->where('asn_id', $id)->exists(), 422, 'ASN memerlukan sedikitnya satu baris.');

        return $this->move($id, ['DRAFT'], 'SUBMITTED', 'asn.submitted');
    }

    public function confirm(string $id): object
    {
        return $this->move($id, ['SUBMITTED'], 'CONFIRMED', 'asn.confirmed');
    }

    public function cancel(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan pembatalan minimal 5 karakter.');
        $asn = $this->move($id, ['DRAFT', 'SUBMITTED', 'CONFIRMED'], 'CANCELLED', 'asn.cancelled', ['reason' => trim($reason)]);
        DB::table('dock_bookings')->where('tenant_id', $this->store->tenantId)->where('asn_id', $id)->where('status', 'BOOKED')->update(['status' => 'CANCELLED', 'updated_at' => now()]);

        return $asn;
    }

    /** Closes receiving; goods not delivered stay unreceived (the shortage is in the ASN lines). */
    public function complete(string $id, ?string $reason): object
    {
        $asn = $this->store->find('asns', $id);
        $short = $this->store->query('asn_lines')->where('asn_id', $id)->whereColumn('received_qty', '<', 'expected_qty')->exists();
        abort_if($short && mb_strlen(trim((string) $reason)) < 5, 422, 'Ada kekurangan penerimaan; isi alasan penutupan (minimal 5 karakter).');
        abort_unless(in_array($asn->status, ['ARRIVED', 'RECEIVING'], true), 422, 'Hanya ASN yang sudah tiba atau sedang diterima yang dapat ditutup.');
        abort_unless($this->store->query('asn_lines')->where('asn_id', $id)->where('received_qty', '>', 0)->exists(), 422, 'Belum ada barang yang diterima.');

        return $this->move($id, ['ARRIVED', 'RECEIVING'], 'RECEIVED', 'asn.received', ['short' => $short, 'reason' => $reason]);
    }

    public function one(string $id): object
    {
        $asn = $this->store->find('asns', $id);
        $asn->supplier_name = $this->store->query('partners')->where('id', $asn->supplier_id)->value('name');
        $asn->warehouse_name = $this->store->query('warehouses')->where('id', $asn->warehouse_id)->value('name');
        $asn->lines = DB::table('asn_lines as l')->where('l.tenant_id', $this->store->tenantId)->join('products as p', function ($j) {
            $j->on('p.id', '=', 'l.product_id')->on('p.tenant_id', '=', 'l.tenant_id');
        })->where('l.asn_id', $id)->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'l.product_id', 'p.code as product_code', 'p.name as product_name', 'p.track_lot', 'p.track_expiry', 'l.expected_qty', 'l.received_qty'])->all();

        return $asn;
    }

    public function list(?string $status, ?string $supplierId, int $page, int $perPage = 50): array
    {
        $q = DB::table('asns as a')->where('a.tenant_id', $this->store->tenantId)->join('partners as s', function ($j) {
            $j->on('s.id', '=', 'a.supplier_id')->on('s.tenant_id', '=', 'a.tenant_id');
        })->when($status, fn ($q) => $q->where('a.status', $status))->when($supplierId, fn ($q) => $q->where('a.supplier_id', $supplierId));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('a.created_at')->forPage($page, $perPage)->get(['a.id', 'a.number', 'a.status', 'a.source', 'a.expected_date', 'a.reference', 's.name as supplier_name']);

        return ['data' => $rows, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }
}
