<?php

namespace App\Domain\Master;

use App\Domain\Shared\TenantStore;
use App\Domain\Shared\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes one tenant's master data from the definitions in MasterRegistry. Every write is one transaction that
 * also writes the audit trail and the outbox event; nothing is deleted (a master in use is deactivated instead).
 */
final class MasterService
{
    /** Digits of the running number in a generated code (CST-20261000001 has five). */
    private const CODE_DIGITS = 5;

    public function __construct(private readonly TenantStore $store) {}

    /** @return array<string, mixed> */
    private function definition(string $resource): array
    {
        return MasterRegistry::find($resource) ?? abort(404, 'Master data tidak dikenal.');
    }

    /** Validation rules of one resource for a create or an update (an update leaves out what cannot change). */
    private function rules(string $resource, bool $creating, ?string $id): array
    {
        $table = $resource;
        $rules = [];
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if (! $creating && ($f['immutable'] ?? false)) {
                continue;
            }
            $name = $f['name'];
            $required = (bool) ($f['required'] ?? false);
            $r = [$required ? 'required' : 'nullable'];
            switch ($f['kind']) {
                case 'number':
                    array_push($r, 'integer', 'min:'.($f['min'] ?? 0), 'max:'.($f['max'] ?? 4294967295));
                    break;
                case 'decimal':
                    array_push($r, 'numeric', 'between:'.$f['min'].','.$f['max']);
                    break;
                case 'boolean':
                    $r[] = 'boolean';
                    break;
                case 'date':
                    array_push($r, 'date_format:Y-m-d');
                    break;
                case 'select':
                    $r[] = Rule::in(array_keys($f['options']));
                    break;
                case 'ref':
                    $r[] = 'uuid';
                    $r[] = Rule::exists(MasterRegistry::find($f['ref']) ? $f['ref'] : $table, 'id')->where('tenant_id', $this->store->tenantId);
                    break;
                default:
                    $r[] = 'string';
                    if (isset($f['max'])) {
                        $r[] = 'max:'.$f['max'];
                    }
            }
            if ($name === 'code') {
                $r[] = Rule::unique($table, 'code')->where('tenant_id', $this->store->tenantId);
            } elseif ($f['unique'] ?? false) {
                $r[] = Rule::unique($table, $name)->where('tenant_id', $this->store->tenantId)->ignore($id);
            }
            $rules[$name] = array_merge($r, $f['rules'] ?? []);
        }

        return $rules;
    }

    /** Trims text, upper-cases codes and keys, keeps only the digits of a tax number, and turns empty text into null. */
    private function normalize(string $resource, array $input): array
    {
        $data = [];
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if (! array_key_exists($f['name'], $input)) {
                continue;
            }
            $v = $input[$f['name']];
            if (is_string($v)) {
                $v = trim($v);
                if ($f['upper'] ?? false) {
                    $v = Str::upper($v);
                }
                if ($f['digits'] ?? false) {
                    $v = preg_replace('/\D+/', '', $v);
                }
            }
            $data[$f['name']] = ($v === '' || $v === null) ? null : $v;
        }

        return $data;
    }

    /**
     * Normalizes and validates one record without writing it.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} the clean values, and the errors per field (empty when valid)
     */
    public function check(string $resource, array $input, bool $creating, ?string $id = null): array
    {
        if ($creating) { // a field left out of a new record takes its declared default
            foreach (MasterRegistry::fieldsOf($resource) as $f) {
                if (array_key_exists('default', $f) && ! array_key_exists($f['name'], $input)) {
                    $input[$f['name']] = $f['default'];
                }
            }
        }
        $data = $this->normalize($resource, $input);
        $v = Validator::make($data, $this->rules($resource, $creating, $id));
        $v->setAttributeNames(array_column(MasterRegistry::fieldsOf($resource), 'label', 'name'));
        if ($v->fails()) {
            return [[], $v->errors()->toArray()];
        }
        $cross = MasterConstraints::check($this->store, $resource, $data, $id);
        if ($cross !== []) {
            return [[], $cross];
        }
        $clean = [];
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if (array_key_exists($f['name'], $data) && ! (! $creating && ($f['immutable'] ?? false))) {
                $value = $data[$f['name']];
                $clean[$f['name']] = $f['kind'] === 'boolean' && $value !== null ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value;
            }
        }

        return [$clean, []];
    }

    /** @return array<string, mixed> */
    private function validated(string $resource, array $input, bool $creating, ?string $id = null): array
    {
        [$clean, $errors] = $this->check($resource, $input, $creating, $id);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** One page of a master, with the readable name of every reference. */
    public function list(string $resource, ?string $search, ?string $active, int $page, int $perPage = 50): array
    {
        $def = $this->definition($resource);
        $query = $this->store->query($resource);
        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($search)).'%';
            $query->where(function ($q) use ($def, $like) {
                foreach ($def['search'] as $column) {
                    $q->orWhereRaw("lower({$column}::text) like ?", [$like]);
                }
            });
        }
        if ($active === '1' || $active === '0') {
            $query->where('active', $active === '1');
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->orderBy('code')->forPage($page, $perPage)->get();
        $this->labelReferences($resource, $rows);

        return ['data' => $rows, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    /** Adds `<field>_label` ("CODE · Name") to each row for every reference field. */
    private function labelReferences(string $resource, $rows): void
    {
        foreach (MasterRegistry::fieldsOf($resource) as $f) {
            if ($f['kind'] !== 'ref') {
                continue;
            }
            $ids = $rows->pluck($f['name'])->filter()->unique()->values()->all();
            $labels = $ids === [] ? collect() : $this->store->query($f['ref'])->whereIn('id', $ids)->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]);
            foreach ($rows as $row) {
                $row->{$f['name'].'_label'} = $row->{$f['name']} ? ($labels[$row->{$f['name']}] ?? null) : null;
            }
        }
    }

    /** Active rows only, for choosing one in a form. */
    public function options(string $resource): array
    {
        $this->definition($resource);

        $parents = array_column(array_filter(MasterRegistry::fieldsOf($resource), fn (array $f) => $f['kind'] === 'ref'), 'name');

        return $this->store->query($resource)->where('active', true)->orderBy('name')->get(array_merge(['id', 'code', 'name'], $parents))->all();
    }

    public function create(string $resource, array $input): object
    {
        $data = $this->validated($resource, $input, true);

        return DB::transaction(function () use ($resource, $data) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            if (($data['code'] ?? null) === null) {
                $data['code'] = $this->nextCode($resource);
            }
            $id = (string) Str::uuid();
            DB::table($resource)->insert($data + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event("master.{$resource}.created", $id, ['code' => $data['code'], 'name' => $data['name']]);

            return $this->one($resource, $id);
        }, 3);
    }

    /**
     * The next generated code of a customer or project: PREFIX-YYYYMM##### with a running number that starts again every month and never
     * repeats (the sequence is locked with the tenant). The number grows past four digits instead of being cut. A code a person brought by
     * import that happens to be the same is skipped.
     */
    public function nextCode(string $resource): string
    {
        $field = collect(MasterRegistry::fieldsOf($resource))->firstWhere('name', 'code');
        abort_unless($field['generated'] ?? false, 500, 'This master has no generated code.');
        $period = now()->format('Ym');
        do {
            $number = DocumentNumbers::next($this->store->tenantId, 'CODE-'.$field['prefix'], $period);
            $code = sprintf('%s-%s%0'.self::CODE_DIGITS.'d', $field['prefix'], $period, $number);
        } while ($this->store->query($resource)->where('code', $code)->exists());

        return $code;
    }

    public function update(string $resource, string $id, array $input): object
    {
        $this->definition($resource);

        return DB::transaction(function () use ($resource, $id, $input) {
            $before = $this->store->find($resource, $id, true);
            $data = $this->validated($resource, $input, false, $id);
            $changes = [];
            foreach ($data as $field => $value) {
                if ((string) ($before->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changes[$field] = ['from' => $before->{$field}, 'to' => $value];
                }
            }
            if ($changes !== []) {
                DB::table($resource)->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($data + ['updated_at' => now()]);
                $this->store->event("master.{$resource}.updated", $id, ['code' => $before->code, 'changes' => $changes]);
            }

            return $this->one($resource, $id);
        }, 3);
    }

    /** Activate or deactivate; a master in use is never deleted. Deactivating needs a reason for the audit trail. */
    public function setActive(string $resource, string $id, bool $active, ?string $reason): object
    {
        $this->definition($resource);
        abort_if(! $active && (trim((string) $reason) === '' || mb_strlen(trim((string) $reason)) < 5), 422, 'Alasan penonaktifan minimal 5 karakter.');

        return DB::transaction(function () use ($resource, $id, $active, $reason) {
            $row = $this->store->find($resource, $id, true);
            if ((bool) $row->active !== $active) {
                DB::table($resource)->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
                $this->store->event($active ? "master.{$resource}.activated" : "master.{$resource}.deactivated", $id, ['code' => $row->code, 'reason' => $reason]);
            }

            return $this->one($resource, $id);
        }, 3);
    }

    public function one(string $resource, string $id): object
    {
        $row = $this->store->find($resource, $id);
        $this->labelReferences($resource, collect([$row]));

        return $row;
    }
}
