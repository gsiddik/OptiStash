<?php

namespace App\Domain\Warehouse;

use App\Domain\Master\MasterConstraints;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The storage hierarchy of a warehouse: Zone > Aisle > Rack > Shelf > Bin. Each warehouse chooses how deep it goes (its granularity);
 * nothing deeper can be created. A blocked node blocks everything below it. Every change is one transaction with audit and outbox.
 */
final class StorageTopology
{
    public const MAX_GENERATED = 500;

    public function __construct(private readonly TenantStore $store) {}

    /** @return list<object> every node of the warehouse in tree order, with `effective_blocked` (own or inherited) and `depth`. */
    public function tree(string $warehouseId): array
    {
        $this->store->find('warehouses', $warehouseId);
        $rows = $this->store->query('storage_nodes')->where('warehouse_id', $warehouseId)->orderBy('code')->get();
        $children = $rows->groupBy(fn ($r) => $r->parent_id ?? '');
        $out = [];
        $walk = function (string $parent, bool $blocked, int $depth) use (&$walk, $children, &$out) {
            foreach ($children[$parent] ?? [] as $node) {
                $node->effective_blocked = $blocked || $node->blocked;
                $node->depth = $depth;
                $out[] = $node;
                $walk($node->id, $node->effective_blocked, $depth + 1);
            }
        };
        $walk('', false, 0);

        return $out;
    }

    private function levelFor(object $warehouse, ?object $parent): string
    {
        $level = $parent === null ? 'ZONE' : (MasterConstraints::LEVELS[array_search($parent->level, MasterConstraints::LEVELS, true) + 1] ?? null);
        abort_if($level === null, 422, 'Bin adalah tingkat terdalam; tidak ada lokasi di bawahnya.');
        abort_if(array_search($level, MasterConstraints::LEVELS, true) > array_search($warehouse->granularity, MasterConstraints::LEVELS, true), 422,
            "Gudang ini hanya dikelola sampai tingkat {$warehouse->granularity}; tingkat {$level} tidak tersedia.");

        return $level;
    }

    private function parentOf(string $warehouseId, ?string $parentId): ?object
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $this->store->query('storage_nodes')->where('id', $parentId)->where('warehouse_id', $warehouseId)->first();
        abort_unless($parent, 422, 'Lokasi induk tidak ditemukan di gudang ini.');
        abort_unless($parent->active, 422, 'Lokasi induk tidak aktif.');

        return $parent;
    }

    public function create(string $warehouseId, ?string $parentId, string $code, string $name, ?int $capacity): object
    {
        return DB::transaction(function () use ($warehouseId, $parentId, $code, $name, $capacity) {
            $warehouse = $this->store->find('warehouses', $warehouseId, true);
            abort_unless($warehouse->active, 422, 'Gudang tidak aktif.');
            $parent = $this->parentOf($warehouseId, $parentId);
            $level = $this->levelFor($warehouse, $parent);
            $code = Str::upper(trim($code));
            abort_if($this->store->query('storage_nodes')->where('warehouse_id', $warehouseId)->where('code', $code)->exists(), 422, "Kode lokasi {$code} sudah dipakai di gudang ini.");
            $id = (string) Str::uuid();
            DB::table('storage_nodes')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'warehouse_id' => $warehouseId, 'parent_id' => $parent?->id, 'level' => $level,
                'code' => $code, 'name' => $name, 'capacity_base_units' => $capacity, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('storage.node_created', $id, ['warehouse_id' => $warehouseId, 'level' => $level, 'code' => $code]);

            return $this->store->find('storage_nodes', $id);
        }, 3);
    }

    /**
     * Creates many sibling nodes at once: {parent code}-{prefix}{number}, for example Z1-A01 ... Z1-A12 under zone Z1.
     *
     * @return list<object>
     */
    public function generate(string $warehouseId, ?string $parentId, string $prefix, int $count, int $start, int $width, ?int $capacity): array
    {
        abort_unless($count >= 1 && $count <= self::MAX_GENERATED, 422, 'Jumlah lokasi 1 sampai '.self::MAX_GENERATED.'.');

        return DB::transaction(function () use ($warehouseId, $parentId, $prefix, $count, $start, $width, $capacity) {
            $warehouse = $this->store->find('warehouses', $warehouseId, true);
            abort_unless($warehouse->active, 422, 'Gudang tidak aktif.');
            $parent = $this->parentOf($warehouseId, $parentId);
            $level = $this->levelFor($warehouse, $parent);
            $stem = ($parent ? $parent->code.'-' : '').Str::upper(trim($prefix));
            $codes = [];
            for ($n = $start; $n < $start + $count; $n++) {
                $codes[] = $stem.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
            }
            $clash = $this->store->query('storage_nodes')->where('warehouse_id', $warehouseId)->whereIn('code', $codes)->pluck('code')->all();
            abort_if($clash !== [], 422, 'Kode sudah dipakai: '.implode(', ', array_slice($clash, 0, 5)).(count($clash) > 5 ? ' dan lainnya' : '').'.');
            $now = now();
            $rows = array_map(fn ($c) => [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'warehouse_id' => $warehouseId, 'parent_id' => $parent?->id, 'level' => $level,
                'code' => $c, 'name' => $c, 'capacity_base_units' => $capacity, 'created_at' => $now, 'updated_at' => $now,
            ], $codes);
            DB::table('storage_nodes')->insert($rows);
            $this->store->event('storage.nodes_generated', $warehouseId, ['parent_id' => $parent?->id, 'level' => $level, 'count' => $count, 'first' => $codes[0], 'last' => end($codes)]);

            return array_map(fn ($r) => (object) $r, $rows);
        }, 3);
    }

    public function update(string $id, string $name, ?int $capacity): object
    {
        return DB::transaction(function () use ($id, $name, $capacity) {
            $node = $this->store->find('storage_nodes', $id, true);
            DB::table('storage_nodes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['name' => $name, 'capacity_base_units' => $capacity, 'updated_at' => now()]);
            $this->store->event('storage.node_updated', $id, ['code' => $node->code]);

            return $this->store->find('storage_nodes', $id);
        }, 3);
    }

    /** Blocks or unblocks a location; the reason is always recorded because a blocked location stops putaway and picking. */
    public function setBlocked(string $id, bool $blocked, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $blocked, $reason) {
            $node = $this->store->find('storage_nodes', $id, true);
            if ((bool) $node->blocked !== $blocked) {
                DB::table('storage_nodes')->where('tenant_id', $this->store->tenantId)->where('id', $id)
                    ->update(['blocked' => $blocked, 'blocked_reason' => $blocked ? trim($reason) : null, 'updated_at' => now()]);
                $this->store->event($blocked ? 'storage.node_blocked' : 'storage.node_unblocked', $id, ['code' => $node->code, 'reason' => trim($reason)]);
            }

            return $this->store->find('storage_nodes', $id);
        }, 3);
    }

    /** Deactivates a location that has no active location below it. A location is never deleted. */
    public function deactivate(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $node = $this->store->find('storage_nodes', $id, true);
            abort_if($this->store->query('storage_nodes')->where('parent_id', $id)->where('active', true)->exists(), 422, 'Nonaktifkan dahulu lokasi di bawahnya.');
            DB::table('storage_nodes')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            $this->store->event('storage.node_deactivated', $id, ['code' => $node->code, 'reason' => trim($reason)]);

            return $this->store->find('storage_nodes', $id);
        }, 3);
    }
}
