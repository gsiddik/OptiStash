<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\TenantStore;
use App\Domain\Warehouse\StorageTopology;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock visibility and control: the stock map of a warehouse with drill-down, the per-product summary, and locking of stock
 * (audit hold, quality hold). Locked, quarantined and rejected stock never counts as available.
 */
final class InventoryService
{
    public const HELD = ['AVAILABLE', 'QUARANTINE'];

    public function __construct(private readonly TenantStore $store) {}

    /** The storage tree of a warehouse with the stock held in each node (own and below), and how full it is. */
    public function map(string $warehouseId): array
    {
        $nodes = (new StorageTopology($this->store))->tree($warehouseId);
        $own = $this->store->query('stock_units')->where('warehouse_id', $warehouseId)->whereNotNull('location_id')->whereIn('status', self::HELD)
            ->selectRaw('location_id, sum(quantity) as qty, count(*) as units, sum(case when locked then quantity else 0 end) as locked_qty')->groupBy('location_id')->get()->keyBy('location_id');
        $byId = [];
        foreach ($nodes as $n) {
            $n->quantity = (int) ($own[$n->id]->qty ?? 0);
            $n->units = (int) ($own[$n->id]->units ?? 0);
            $n->locked_quantity = (int) ($own[$n->id]->locked_qty ?? 0);
            $byId[$n->id] = $n;
        }
        foreach ($nodes as $n) { // roll the leaf quantities up to every ancestor
            for ($p = $n->parent_id; $p !== null; $p = $byId[$p]->parent_id ?? null) {
                $byId[$p]->quantity += (int) ($own[$n->id]->qty ?? 0);
                $byId[$p]->units += (int) ($own[$n->id]->units ?? 0);
                $byId[$p]->locked_quantity += (int) ($own[$n->id]->locked_qty ?? 0);
            }
        }
        foreach ($nodes as $n) {
            $n->utilization = $n->capacity_base_units ? round(100 * min($n->quantity, $n->capacity_base_units) / $n->capacity_base_units, 1) : null;
        }

        return $nodes;
    }

    /** Units stored at a location or anywhere below it. */
    public function unitsAt(string $locationId): array
    {
        $node = $this->store->find('storage_nodes', $locationId);
        $ids = [$node->id];
        $frontier = [$node->id];
        while ($frontier) {
            $frontier = $this->store->query('storage_nodes')->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return DB::table('stock_units as u')->where('u.tenant_id', $this->store->tenantId)->whereIn('u.location_id', $ids)->whereIn('u.status', self::HELD)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->join('storage_nodes as l', fn ($j) => $j->on('l.id', '=', 'u.location_id')->on('l.tenant_id', '=', 'u.tenant_id'))
            ->orderBy('l.code')->orderBy('u.lpn')->limit(500)
            ->get(['u.id', 'u.lpn', 'u.lot_no', 'u.expiry_date', 'u.quantity', 'u.status', 'u.locked', 'u.lock_reason', 'p.code as product_code', 'p.name as product_name', 'l.code as location_code'])->all();
    }

    /** On-hand per product: available (not locked), locked, quarantined, and waiting for putaway. */
    public function summary(?string $warehouseId): array
    {
        return DB::table('stock_units as u')->where('u.tenant_id', $this->store->tenantId)->when($warehouseId, fn ($q) => $q->where('u.warehouse_id', $warehouseId))
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 'u.product_id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->groupBy('p.id', 'p.code', 'p.name')->orderBy('p.code')
            ->selectRaw("p.id, p.code, p.name,
                coalesce(sum(case when u.status = 'AVAILABLE' and not u.locked then u.quantity - u.reserved_qty end), 0) as available,
                coalesce(sum(case when u.status = 'AVAILABLE' then u.reserved_qty end), 0) as reserved,
                coalesce(sum(case when u.status = 'AVAILABLE' and u.locked then u.quantity end), 0) as locked,
                coalesce(sum(case when u.status = 'QUARANTINE' then u.quantity end), 0) as quarantine,
                coalesce(sum(case when u.status = 'PENDING_PUTAWAY' then u.quantity end), 0) as pending_putaway")->get()->all();
    }

    private function movement(string $unitId, string $type, string $reason): void
    {
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'stock_unit_id' => $unitId, 'type' => $type, 'quantity' => 0,
            'reason' => $reason, 'actor_id' => $this->store->actorId, 'created_at' => now(),
        ]);
    }

    /** Locks or unlocks one stored unit. A lock needs a reason; unlocking records why it ended. Only AVAILABLE stock can be locked. */
    public function setLocked(string $unitId, bool $locked, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($unitId, $locked, $reason) {
            $unit = $this->store->find('stock_units', $unitId, true);
            abort_unless($unit->status === 'AVAILABLE', 422, 'Hanya stok tersedia yang dapat dikunci atau dibuka.');
            if ((bool) $unit->locked === $locked) {
                return $unit;
            }
            DB::table('stock_units')->where('tenant_id', $this->store->tenantId)->where('id', $unitId)->update($locked
                ? ['locked' => true, 'lock_reason' => trim($reason), 'locked_by' => $this->store->actorId, 'locked_at' => now(), 'updated_at' => now()]
                : ['locked' => false, 'lock_reason' => null, 'locked_by' => null, 'locked_at' => null, 'updated_at' => now()]);
            $this->movement($unitId, $locked ? 'LOCK' : 'UNLOCK', trim($reason));
            $this->store->event($locked ? 'inventory.locked' : 'inventory.unlocked', $unitId, ['lpn' => $unit->lpn, 'reason' => trim($reason)]);

            return $this->store->find('stock_units', $unitId);
        }, 3);
    }

    /** Locks every available unit at a location and below (for example a rack under audit). @return int units locked */
    public function lockLocation(string $locationId, string $reason): int
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');
        $ids = array_column($this->unitsAt($locationId), 'id', 'id');
        $n = 0;
        foreach ($ids as $id) {
            $unit = $this->store->find('stock_units', $id);
            if ($unit->status === 'AVAILABLE' && ! $unit->locked) {
                $this->setLocked($id, true, $reason);
                $n++;
            }
        }

        return $n;
    }
}
