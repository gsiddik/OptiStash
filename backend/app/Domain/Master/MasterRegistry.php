<?php

namespace App\Domain\Master;

/**
 * The master data of the warehouse, described once. The same definition drives validation, the API, the audit
 * trail and the generic screen (`GET master/schema`), so adding a master or a field is a change here and a migration.
 *
 * Field keys: name, label, kind (text, textarea, number, decimal, select, boolean, date, ref), required, max, min,
 * options (value => label, for select), ref (resource name, for ref), list (shown in the table), immutable (cannot change after
 * creation), unique (unique per tenant besides the code), upper (stored upper case), digits (stored as digits only),
 * rules (extra Laravel rules), hint.
 */
final class MasterRegistry
{
    public const GROUPS = [];

    private const CODE = ['name' => 'code', 'label' => 'Kode', 'kind' => 'text', 'required' => true, 'max' => 40, 'immutable' => true, 'upper' => true, 'list' => true,
        'rules' => ['regex:/^[A-Z0-9][A-Z0-9._\/-]*$/'], 'hint' => 'Huruf besar, angka, titik, garis miring, garis bawah atau strip. Tidak dapat diubah setelah dibuat.'];

    private const NAME = ['name' => 'name', 'label' => 'Nama', 'kind' => 'text', 'required' => true, 'max' => 200, 'list' => true];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function fieldsOf(string $resource): array
    {
        return self::find($resource)['fields'] ?? [];
    }

    /** What the screen needs to draw every master, without server-only rules. */
    public static function schema(): array
    {
        $resources = [];
        foreach (self::all() as $key => $definition) {
            $resources[] = [
                'key' => $key, 'label' => $definition['label'], 'singular' => $definition['singular'], 'group' => $definition['group'],
                'fields' => array_map(fn (array $f) => array_diff_key($f, ['rules' => 1]), $definition['fields']),
            ];
        }

        return ['groups' => self::GROUPS, 'resources' => $resources];
    }
}
