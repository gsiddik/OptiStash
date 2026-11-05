<?php

namespace App\Domain\Inbound;

use App\Domain\Master\MasterConstraints;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receiving against an ASN, the quality decision on received goods (ISO 9001:2015 clause 8.7, control of nonconforming outputs) and
 * system-directed putaway. Every received pallet or carton becomes one stock unit with an LPN; every change of a unit is written to the
 * append-only movement ledger in the same transaction as the change.
 */
final class ReceivingService
{
    public const NC_CATEGORIES = ['DAMAGED', 'EXPIRED', 'WRONG_ITEM', 'QUANTITY', 'TEMPERATURE', 'OTHER'];

    public function __construct(private readonly TenantStore $store) {}

    private function movement(string $unitId, string $type, int $qty, ?string $from, ?string $to, ?string $reason): void
    {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stock_unit_id' => $unitId, 'type' => $type, 'quantity' => $qty,
            'from_location_id' => $from, 'to_location_id' => $to, 'reason' => $reason, 'actor_id' => $this->store->actorId, 'created_at' => now(),
        ]);
    }

    /**
     * Receives one pallet/carton of an ASN line. A conforming unit waits for putaway; a nonconforming one is quarantined at once and
     * opens a non-conformance record, so it can never be put away or picked until a quality decision releases it.
     *
     * @param  array{lot_no?:?string,expiry_date?:?string,nonconforming?:bool,category?:?string,description?:?string,instrument_id?:?string}  $data
     */
    public function receive(string $asnLineId, int $qty, array $data): object
    {
        return DB::transaction(function () use ($asnLineId, $qty, $data) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $line = $this->store->find('asn_lines', $asnLineId, true);
            $asn = $this->store->find('asns', $line->asn_id, true);
            abort_unless(in_array($asn->status, ['ARRIVED', 'RECEIVING'], true), 422, 'Penerimaan hanya dapat dilakukan setelah truk gate-in dan sebelum ASN ditutup.');
            $product = $this->store->find('products', $line->product_id);
            // Chilled and frozen goods are measured at the dock: a calibrated, usable instrument is required (ISO 9001:2015 7.1.5).
            $instrument = null;
            if ($product->temperature_class !== 'AMBIENT' || ! empty($data['instrument_id'])) {
                abort_if(empty($data['instrument_id']), 422, "Produk {$product->code} berkelas suhu {$product->temperature_class}: pilih alat ukur yang terkalibrasi.");
                $instrument = (new \App\Domain\Quality\CalibrationService($this->store))->assertUsable($data['instrument_id']);
            }
            abort_if($line->received_qty + $qty > $line->expected_qty, 422, 'Jumlah melebihi sisa ASN ('.($line->expected_qty - $line->received_qty).'). Lebih kirim harus diselesaikan dengan pemasok.');

            $lot = isset($data['lot_no']) && trim($data['lot_no']) !== '' ? trim($data['lot_no']) : null;
            $expiry = $data['expiry_date'] ?? null;
            abort_if($product->track_lot && $lot === null, 422, "Produk {$product->code} dilacak per lot: nomor lot wajib diisi.");
            abort_if($product->track_expiry && $expiry === null, 422, "Produk {$product->code} berkedaluwarsa: tanggal kedaluwarsa wajib diisi.");
            $nonconforming = (bool) ($data['nonconforming'] ?? false);
            $category = $data['category'] ?? null;
            if ($expiry !== null) {
                $days = (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($expiry), false);
                if ($days < 0) {
                    abort_unless($nonconforming, 422, 'Barang sudah kedaluwarsa: tandai tidak sesuai (EXPIRED) agar masuk karantina.');
                    $category ??= 'EXPIRED';
                } elseif ($product->min_remaining_shelf_life_days !== null && $days < $product->min_remaining_shelf_life_days) {
                    abort_unless($nonconforming, 422, "Sisa umur simpan {$days} hari kurang dari minimum {$product->min_remaining_shelf_life_days} hari: tandai tidak sesuai agar masuk karantina.");
                    $category ??= 'EXPIRED';
                }
            }
            if ($nonconforming) {
                abort_unless(in_array($category, self::NC_CATEGORIES, true), 422, 'Kategori ketidaksesuaian wajib dipilih.');
                abort_if(mb_strlen(trim((string) ($data['description'] ?? ''))) < 5, 422, 'Uraian ketidaksesuaian minimal 5 karakter.');
            }

            $period = now()->format('Ym');
            $unitId = (string) Str::uuid();
            $lpn = sprintf('LPN-%s%06d', $period, DocumentNumbers::next($this->store->tenantId, 'LPN', $period));
            DB::table('stock_units')->insert([
                'id' => $unitId, 'tenant_id' => $this->store->tenantId, 'lpn' => $lpn, 'warehouse_id' => $asn->warehouse_id, 'product_id' => $line->product_id,
                'asn_line_id' => $line->id, 'lot_no' => $lot, 'expiry_date' => $expiry, 'quantity' => $qty, 'location_id' => null,
                'status' => $nonconforming ? 'QUARANTINE' : 'PENDING_PUTAWAY', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('asn_lines')->where('tenant_id', $this->store->tenantId)->where('id', $line->id)->update(['received_qty' => $line->received_qty + $qty]);
            DB::table('asns')->where('tenant_id', $this->store->tenantId)->where('id', $asn->id)->update(['status' => 'RECEIVING', 'updated_at' => now()]);
            $this->movement($unitId, 'RECEIVE', $qty, null, null, null);
            $this->store->event('receiving.received', $unitId, ['asn' => $asn->number, 'lpn' => $lpn, 'product' => $product->code, 'quantity' => $qty, 'nonconforming' => $nonconforming, 'instrument' => $instrument?->code]);

            if ($nonconforming) {
                $this->openNonconformance($unitId, $category, trim((string) $data['description']));
            } else {
                $this->openPutaway($unitId);
            }

            return $this->unit($unitId);
        }, 3);
    }

    private function openNonconformance(string $unitId, string $category, string $description): void
    {
        $period = now()->format('Ym');
        $number = sprintf('NC-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'NC', $period));
        $id = (string) Str::uuid();
        DB::table('nonconformances')->insert([
            'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'source' => 'RECEIPT', 'stock_unit_id' => $unitId, 'category' => $category,
            'description' => $description, 'disposition' => 'QUARANTINE', 'status' => 'OPEN', 'raised_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->store->event('nonconformance.raised', $id, ['number' => $number, 'category' => $category, 'stock_unit_id' => $unitId]);
    }

    private function openPutaway(string $unitId): void
    {
        $unit = $this->store->find('stock_units', $unitId);
        DB::table('putaway_tasks')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stock_unit_id' => $unitId,
            'recommended_location_id' => $this->recommend($unit), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Quality decision on a unit (qc.inspect): QUARANTINE a unit still waiting for putaway, RELEASE a quarantined unit (conforming after
     * all) or REJECT it (returned to the supplier or scrapped). The reason is always recorded; a unit already stored cannot be decided here.
     */
    public function decide(string $unitId, string $decision, string $reason): object
    {
        abort_unless(in_array($decision, ['QUARANTINE', 'RELEASE', 'REJECT'], true), 422, 'Keputusan tidak dikenal.');
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan keputusan minimal 5 karakter.');

        return DB::transaction(function () use ($unitId, $decision, $reason) {
            $unit = $this->store->find('stock_units', $unitId, true);
            $reason = trim($reason);
            if ($decision === 'QUARANTINE') {
                abort_unless($unit->status === 'PENDING_PUTAWAY', 422, 'Hanya unit yang menunggu putaway yang dapat dikarantina di sini.');
                DB::table('putaway_tasks')->where('tenant_id', $this->store->tenantId)->where('stock_unit_id', $unitId)->where('status', 'OPEN')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                $this->setStatus($unitId, 'QUARANTINE');
                $this->movement($unitId, 'QC_QUARANTINE', $unit->quantity, null, null, $reason);
                $this->openNonconformance($unitId, 'OTHER', $reason);
            } else {
                abort_unless($unit->status === 'QUARANTINE', 422, 'Hanya unit karantina yang dapat dilepas atau ditolak.');
                $nc = $this->store->query('nonconformances')->where('stock_unit_id', $unitId)->where('status', 'OPEN')->orderByDesc('created_at')->first();
                if ($nc) {
                    DB::table('nonconformances')->where('tenant_id', $this->store->tenantId)->where('id', $nc->id)->update([
                        'disposition' => $decision, 'status' => 'CLOSED', 'decided_by' => $this->store->actorId, 'decided_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($decision === 'RELEASE') {
                    $this->setStatus($unitId, 'PENDING_PUTAWAY');
                    $this->movement($unitId, 'QC_RELEASE', $unit->quantity, null, null, $reason);
                    $this->openPutaway($unitId);
                } else {
                    $this->setStatus($unitId, 'REJECTED');
                    $this->movement($unitId, 'QC_REJECT', $unit->quantity, null, null, $reason);
                }
            }
            $this->store->event('qc.decision', $unitId, ['lpn' => $unit->lpn, 'decision' => $decision, 'reason' => $reason]);

            return $this->unit($unitId);
        }, 3);
    }

    private function setStatus(string $unitId, string $status): void
    {
        DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $unitId)->update(['status' => $status, 'updated_at' => now()]);
    }

    /** Free capacity of a location in base units (null = unlimited): stored stock and the units already directed there, except $exceptUnit's own. */
    private function freeCapacity(object $node, string $exceptUnit): ?int
    {
        if ($node->capacity_base_units === null) {
            return null;
        }
        $stored = (int) $this->store->query('stock_units')->where('location_id', $node->id)->whereIn('status', ['AVAILABLE', 'QUARANTINE'])->sum('quantity');
        $directed = (int) DB::table('putaway_tasks as t')->where('t.tenant_id', $this->store->tenantId)->where('t.status', 'OPEN')->where('t.recommended_location_id', $node->id)
            ->where('t.stock_unit_id', '!=', $exceptUnit)
            ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 't.stock_unit_id')->on('u.tenant_id', '=', 't.tenant_id'))->sum('u.quantity');

        return max(0, $node->capacity_base_units - $stored - $directed);
    }

    /** True when the node or any ancestor is blocked or inactive. */
    private function unusable(object $node, \Illuminate\Support\Collection $byId): bool
    {
        for ($n = $node; $n !== null; $n = $n->parent_id ? ($byId[$n->parent_id] ?? null) : null) {
            if ($n->blocked || ! $n->active) {
                return true;
            }
        }

        return false;
    }

    /** Storage locations of the unit's warehouse that can take it now: leaf level of the warehouse, usable, with room. */
    private function candidates(object $unit): array
    {
        $warehouse = $this->store->find('warehouses', $unit->warehouse_id);
        $leaf = $warehouse->granularity;
        $all = $this->store->query('storage_nodes')->where('warehouse_id', $unit->warehouse_id)->get();
        $byId = $all->keyBy('id');
        $out = [];
        foreach ($all->where('level', $leaf)->sortBy('code') as $node) {
            if ($this->unusable($node, $byId)) {
                continue;
            }
            $free = $this->freeCapacity($node, $unit->id);
            if ($free === null || $free >= $unit->quantity) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** System-directed putaway: a location already holding the same product (consolidation) first, otherwise the first empty location by code. */
    public function recommend(object $unit): ?string
    {
        $nodes = $this->candidates($unit);
        if ($nodes === []) {
            return null;
        }
        $same = $this->store->query('stock_units')->where('product_id', $unit->product_id)->where('status', 'AVAILABLE')->whereNotNull('location_id')->pluck('location_id')->all();
        foreach ($nodes as $node) {
            if (in_array($node->id, $same, true)) {
                return $node->id;
            }
        }
        $occupied = $this->store->query('stock_units')->whereIn('status', ['AVAILABLE', 'QUARANTINE'])->whereNotNull('location_id')->pluck('location_id')->all();
        foreach ($nodes as $node) {
            if (! in_array($node->id, $occupied, true)) {
                return $node->id;
            }
        }

        return $nodes[0]->id;
    }

    /** Confirms a putaway task at the recommended location, or at another one with a reason (override). */
    public function putaway(string $taskId, ?string $locationId, ?string $overrideReason): object
    {
        return DB::transaction(function () use ($taskId, $locationId, $overrideReason) {
            $task = $this->store->find('putaway_tasks', $taskId, true);
            abort_unless($task->status === 'OPEN', 422, 'Tugas putaway sudah selesai atau dibatalkan.');
            $unit = $this->store->find('stock_units', $task->stock_unit_id, true);
            abort_unless($unit->status === 'PENDING_PUTAWAY', 422, 'Unit ini tidak menunggu putaway.');
            $target = $locationId ?? $task->recommended_location_id;
            abort_if($target === null, 422, 'Tidak ada lokasi yang direkomendasikan; pilih lokasi secara manual.');
            $overridden = $target !== $task->recommended_location_id;
            abort_if($overridden && mb_strlen(trim((string) $overrideReason)) < 5, 422, 'Memilih lokasi selain rekomendasi memerlukan alasan (minimal 5 karakter).');
            $ids = array_map(fn ($n) => $n->id, $this->candidates($unit));
            abort_unless(in_array($target, $ids, true), 422, 'Lokasi tidak dapat dipakai: bukan tingkat penyimpanan gudang ini, diblokir, tidak aktif, atau kapasitas tidak cukup.');
            DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $unit->id)->update(['location_id' => $target, 'status' => 'AVAILABLE', 'updated_at' => now()]);
            DB::table('putaway_tasks')->where('tenant_id', $this->store->tenantId)->where('id', $taskId)->update([
                'status' => 'DONE', 'confirmed_location_id' => $target, 'overridden' => $overridden, 'override_reason' => $overridden ? trim((string) $overrideReason) : null,
                'completed_by' => $this->store->actorId, 'completed_at' => now(), 'updated_at' => now(),
            ]);
            $this->movement($unit->id, 'PUTAWAY', $unit->quantity, null, $target, $overridden ? trim((string) $overrideReason) : null);
            $this->store->event('putaway.completed', $taskId, ['lpn' => $unit->lpn, 'location_id' => $target, 'overridden' => $overridden]);

            return $this->unit($unit->id);
        }, 3);
    }

    /** The locations a putaway task could be completed at (same rules the completion enforces), for the override picker. */
    public function taskLocations(string $taskId): array
    {
        $task = $this->store->find('putaway_tasks', $taskId);
        $unit = $this->store->find('stock_units', $task->stock_unit_id);

        return array_map(fn ($n) => ['id' => $n->id, 'code' => $n->code, 'recommended' => $n->id === $task->recommended_location_id], $this->candidates($unit));
    }

    public function unit(string $id): object
    {
        $u = DB::table('stock_units as u')->where('u.tenant_id', $this->store->tenantId)->where('u.id', $id)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('storage_nodes as l', fn ($j) => $j->on('l.id', '=', 'u.location_id')->on('l.tenant_id', '=', 'u.tenant_id'))
            ->firstOrFail(['u.*', 'p.code as product_code', 'p.name as product_name', 'l.code as location_code']);

        return $u;
    }

    /** The data a printer needs for the LPN label (the barcode value is the LPN itself). */
    public function label(string $id): array
    {
        $u = $this->unit($id);

        return ['barcode' => $u->lpn, 'lpn' => $u->lpn, 'product_code' => $u->product_code, 'product_name' => $u->product_name,
            'lot_no' => $u->lot_no, 'expiry_date' => $u->expiry_date, 'quantity' => $u->quantity, 'received_at' => $u->received_at];
    }

    /** Units in $status (default QUARANTINE) of the tenant, newest first. */
    public function units(string $status): array
    {
        abort_unless(in_array($status, ['QUARANTINE', 'PENDING_PUTAWAY', 'AVAILABLE', 'REJECTED'], true), 422, 'Status tidak dikenal.');

        return DB::table('stock_units as u')->where('u.tenant_id', $this->store->tenantId)->where('u.status', $status)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('storage_nodes as l', fn ($j) => $j->on('l.id', '=', 'u.location_id')->on('l.tenant_id', '=', 'u.tenant_id'))
            ->orderByDesc('u.received_at')->limit(200)
            ->get(['u.id', 'u.lpn', 'u.lot_no', 'u.expiry_date', 'u.quantity', 'u.status', 'p.code as product_code', 'p.name as product_name', 'l.code as location_code'])->all();
    }

    public function openPutawayTasks(): array
    {
        return DB::table('putaway_tasks as t')->where('t.tenant_id', $this->store->tenantId)->where('t.status', 'OPEN')
            ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 't.stock_unit_id')->on('u.tenant_id', '=', 't.tenant_id'))
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('storage_nodes as l', fn ($j) => $j->on('l.id', '=', 't.recommended_location_id')->on('l.tenant_id', '=', 't.tenant_id'))
            ->orderBy('t.created_at')->limit(200)
            ->get(['t.id', 't.stock_unit_id', 'u.lpn', 'u.quantity', 'u.lot_no', 'p.code as product_code', 'p.name as product_name', 't.recommended_location_id', 'l.code as recommended_code'])->all();
    }

    public function nonconformances(?string $status): array
    {
        return $this->store->query('nonconformances')->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->limit(200)->get()->all();
    }

    /** @return list<string> the storage levels, deepest last (re-exported for callers that only know this service) */
    public static function levels(): array
    {
        return MasterConstraints::LEVELS;
    }
}
