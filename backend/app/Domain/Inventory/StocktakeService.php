<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle counts. COUNTING (counters enter the physical quantity per stock unit; any difference needs a reason) -> PENDING_APPROVAL (a
 * supervisor reviews the discrepancies) -> COMPLETED (approved: the differences are booked as adjustments in the movement ledger).
 * The approver can never be someone who counted or submitted: four eyes on every stock adjustment.
 */
final class StocktakeService
{
    public function __construct(private readonly TenantStore $store) {}

    public function start(string $warehouseId, ?string $locationId, ?string $notes): object
    {
        return DB::transaction(function () use ($warehouseId, $locationId, $notes) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $warehouse = $this->store->find('warehouses', $warehouseId);
            abort_unless($warehouse->active, 422, 'Gudang tidak aktif.');
            $inventory = new InventoryService($this->store);
            if ($locationId !== null) {
                $node = $this->store->find('storage_nodes', $locationId);
                abort_unless($node->warehouse_id === $warehouseId, 422, 'Lokasi bukan bagian gudang ini.');
                $units = array_map(fn ($u) => $u->id, $inventory->unitsAt($locationId));
            } else {
                $units = $this->store->query('stock_units')->where('warehouse_id', $warehouseId)->where('status', 'AVAILABLE')->whereNotNull('location_id')->pluck('id')->all();
            }
            $units = $this->store->query('stock_units')->whereIn('id', $units)->where('status', 'AVAILABLE')->get(['id', 'quantity']);
            abort_if($units->isEmpty(), 422, 'Tidak ada stok tersedia pada cakupan ini.');
            $busy = DB::table('stocktake_lines as l')->where('l.tenant_id', $this->store->tenantId)->whereIn('l.stock_unit_id', $units->pluck('id'))
                ->join('stocktakes as s', fn ($j) => $j->on('s.id', '=', 'l.stocktake_id')->on('s.tenant_id', '=', 'l.tenant_id'))
                ->whereIn('s.status', ['COUNTING', 'PENDING_APPROVAL'])->exists();
            abort_if($busy, 422, 'Sebagian stok sudah termasuk dalam penghitungan lain yang belum selesai.');

            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('CC-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'CC', $period));
            DB::table('stocktakes')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                'status' => 'COUNTING', 'notes' => $notes, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('stocktake_lines')->insert($units->map(fn ($u) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stocktake_id' => $id, 'stock_unit_id' => $u->id, 'expected_qty' => $u->quantity,
            ])->all());
            $this->store->event('stocktake.started', $id, ['number' => $number, 'units' => $units->count()]);

            return $this->one($id);
        }, 3);
    }

    public function count(string $lineId, int $qty, ?string $reason): object
    {
        return DB::transaction(function () use ($lineId, $qty, $reason) {
            $line = $this->store->find('stocktake_lines', $lineId, true);
            $take = $this->store->find('stocktakes', $line->stocktake_id, true);
            abort_unless($take->status === 'COUNTING', 422, 'Penghitungan tidak sedang berlangsung.');
            abort_if($qty !== $line->expected_qty && mb_strlen(trim((string) $reason)) < 5, 422, 'Selisih dengan catatan sistem memerlukan alasan (minimal 5 karakter).');
            DB::table('stocktake_lines')->where('tenant_id', $this->store->tenantId)->where('id', $lineId)->update([
                'counted_qty' => $qty, 'variance_reason' => $qty === $line->expected_qty ? null : trim((string) $reason), 'counted_by' => $this->store->actorId, 'counted_at' => now(),
            ]);

            return $this->store->find('stocktake_lines', $lineId);
        }, 3);
    }

    public function submit(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $take = $this->store->find('stocktakes', $id, true);
            abort_unless($take->status === 'COUNTING', 422, 'Penghitungan tidak sedang berlangsung.');
            abort_if($this->store->query('stocktake_lines')->where('stocktake_id', $id)->whereNull('counted_qty')->exists(), 422, 'Masih ada unit yang belum dihitung.');
            DB::table('stocktakes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'PENDING_APPROVAL', 'submitted_by' => $this->store->actorId, 'updated_at' => now()]);
            $this->store->event('stocktake.submitted', $id, ['number' => $take->number]);

            return $this->one($id);
        }, 3);
    }

    /** Sends a submitted count back to the counters for a recount. */
    public function reject(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $take = $this->store->find('stocktakes', $id, true);
            abort_unless($take->status === 'PENDING_APPROVAL', 422, 'Hanya penghitungan yang menunggu persetujuan yang dapat dikembalikan.');
            DB::table('stocktakes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'COUNTING', 'submitted_by' => null, 'updated_at' => now()]);
            $this->store->event('stocktake.rejected', $id, ['number' => $take->number, 'reason' => trim($reason)]);

            return $this->one($id);
        }, 3);
    }

    public function approve(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $take = $this->store->find('stocktakes', $id, true);
            abort_unless($take->status === 'PENDING_APPROVAL', 422, 'Hanya penghitungan yang menunggu persetujuan yang dapat disetujui.');
            $actor = $this->store->actorId;
            $involved = $take->submitted_by === $actor || $this->store->query('stocktake_lines')->where('stocktake_id', $id)->where('counted_by', $actor)->exists();
            abort_if($involved, 403, 'Anda ikut menghitung atau mengajukan penghitungan ini; persetujuan harus dari orang lain.');
            $lines = $this->store->query('stocktake_lines')->where('stocktake_id', $id)->whereColumn('counted_qty', '!=', 'expected_qty')->get();
            foreach ($lines as $l) {
                $unit = $this->store->find('stock_units', $l->stock_unit_id, true);
                abort_unless($unit->status === 'AVAILABLE' && $unit->quantity === $l->expected_qty, 409, "Stok {$unit->lpn} berubah sejak penghitungan dimulai; hitung ulang.");
                DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $unit->id)->update([
                    'quantity' => $l->counted_qty, 'status' => $l->counted_qty === 0 ? 'DEPLETED' : 'AVAILABLE', 'updated_at' => now(),
                ]);
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stock_unit_id' => $unit->id, 'type' => 'ADJUST', 'quantity' => $l->counted_qty - $l->expected_qty,
                    'from_location_id' => $unit->location_id, 'to_location_id' => $unit->location_id, 'reason' => $take->number.': '.$l->variance_reason, 'actor_id' => $actor, 'created_at' => now(),
                ]);
            }
            DB::table('stocktakes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'COMPLETED', 'decided_by' => $actor, 'decided_at' => now(), 'updated_at' => now()]);
            $this->store->event('stocktake.approved', $id, ['number' => $take->number, 'adjustments' => $lines->count()]);

            return $this->one($id);
        }, 3);
    }

    public function cancel(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $take = $this->store->find('stocktakes', $id, true);
            abort_unless(in_array($take->status, ['COUNTING', 'PENDING_APPROVAL'], true), 422, 'Penghitungan sudah selesai atau dibatalkan.');
            DB::table('stocktakes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->store->event('stocktake.cancelled', $id, ['number' => $take->number, 'reason' => trim($reason)]);

            return $this->one($id);
        }, 3);
    }

    public function one(string $id): object
    {
        $take = $this->store->find('stocktakes', $id);
        $take->lines = DB::table('stocktake_lines as l')->where('l.tenant_id', $this->store->tenantId)->where('l.stocktake_id', $id)
            ->join('stock_units as u', fn ($j) => $j->on('u.id', '=', 'l.stock_unit_id')->on('u.tenant_id', '=', 'l.tenant_id'))
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->leftJoin('storage_nodes as n', fn ($j) => $j->on('n.id', '=', 'u.location_id')->on('n.tenant_id', '=', 'u.tenant_id'))
            ->orderBy('n.code')->orderBy('u.lpn')
            ->get(['l.id', 'u.lpn', 'p.code as product_code', 'p.name as product_name', 'n.code as location_code', 'l.expected_qty', 'l.counted_qty', 'l.variance_reason'])->all();

        return $take;
    }

    public function list(?string $status): array
    {
        return $this->store->query('stocktakes')->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->limit(100)->get()->all();
    }
}
