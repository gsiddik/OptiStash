<?php

namespace App\Domain\Master;

use App\Domain\Shared\TenantStore;

/** Adds the readable "CODE · Name" of master data references (`<field>_label`) to rows that point at them, without a query per row. */
final class MasterLabels
{
    /**
     * @param  iterable<object>  $rows
     * @param  array<string, string>  $references  column => master table
     */
    public static function annotate(TenantStore $store, iterable $rows, array $references): void
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        foreach ($references as $column => $table) {
            $ids = collect($rows)->pluck($column)->filter()->unique()->values()->all();
            $labels = $ids === [] ? collect() : $store->query($table)->whereIn('id', $ids)->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]);
            foreach ($rows as $row) {
                $row->{$column.'_label'} = $row->{$column} ? ($labels[$row->{$column}] ?? null) : null;
            }
        }
    }
}
