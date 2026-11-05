<?php

namespace App\Domain\Master;

use App\Domain\Shared\TenantStore;

/** Rules between the fields of one master that the generic field validation cannot express. Returns errors per field (empty when valid). */
final class MasterConstraints
{
    public const LEVELS = ['ZONE', 'AISLE', 'RACK', 'SHELF', 'BIN'];

    /**
     * @param  array<string, mixed>  $data  the normalized values being written (an update only carries what changes)
     * @return array<string, list<string>>
     */
    public static function check(TenantStore $store, string $resource, array $data, ?string $id): array
    {
        $before = $id ? $store->query($resource)->where('id', $id)->first() : null;
        $merged = fn (string $field) => array_key_exists($field, $data) ? $data[$field] : ($before->{$field} ?? null);
        $errors = [];

        if ($resource === 'products') {
            if ($merged('rotation_policy') === 'FEFO' && ! filter_var($merged('track_expiry'), FILTER_VALIDATE_BOOLEAN)) {
                $errors['track_expiry'][] = 'FEFO memerlukan pelacakan tanggal kedaluwarsa.';
            }
            if (filter_var($merged('track_expiry'), FILTER_VALIDATE_BOOLEAN) && ! filter_var($merged('track_lot'), FILTER_VALIDATE_BOOLEAN)) {
                $errors['track_lot'][] = 'Produk berkedaluwarsa harus dilacak per lot.';
            }
            if ($merged('min_remaining_shelf_life_days') !== null && $merged('shelf_life_days') !== null && (int) $merged('min_remaining_shelf_life_days') > (int) $merged('shelf_life_days')) {
                $errors['min_remaining_shelf_life_days'][] = 'Sisa umur simpan minimum tidak boleh melebihi umur simpan.';
            }
        }

        if ($resource === 'warehouses' && $before && array_key_exists('granularity', $data)) {
            $deepest = $store->query('storage_nodes')->where('warehouse_id', $id)->get(['level'])->map(fn ($r) => array_search($r->level, self::LEVELS, true))->max();
            if ($deepest !== null && $deepest !== false && array_search($data['granularity'], self::LEVELS, true) < $deepest) {
                $errors['granularity'][] = 'Gudang sudah memiliki lokasi sampai tingkat '.self::LEVELS[$deepest].'; tingkat terdalam tidak dapat dipersempit.';
            }
        }

        if ($resource === 'product_packaging' && ($data['uom_id'] ?? null) !== null) {
            $base = $store->query('products')->where('id', $merged('product_id'))->value('base_uom_id');
            if ($base !== null && $base === $data['uom_id'] && (int) $merged('base_quantity') !== 1) {
                $errors['base_quantity'][] = 'Kemasan dengan satuan dasar produk harus berisi 1.';
            }
        }

        return $errors;
    }
}
