<?php

namespace App\Domain\Access;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant role management. Guards: system roles are fixed (synced from the catalog), a member can only grant permissions they
 * hold themselves (no escalation), nobody changes their own roles, and supplier-portal access needs a supplier-bound membership.
 */
class RoleService
{
    /** @return list<object> */
    public function roles(TenantStore $s): array
    {
        $grants = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $s->query('roles')->select('id'))->get(['rp.role_id', 'p.code'])->groupBy('role_id');
        $members = DB::table('membership_roles')->whereIn('role_id', $s->query('roles')->select('id'))
            ->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return $s->query('roles')->orderByDesc('is_system')->orderBy('name')->get()->map(function ($role) use ($grants, $members) {
            $role->permissions = ($grants[$role->id] ?? collect())->pluck('code')->sort()->values()->all();
            $role->member_count = (int) ($members[$role->id] ?? 0);

            return $role;
        })->all();
    }

    /** @param list<string> $own the acting member's permissions */
    public function create(TenantStore $s, array $data, array $own): object
    {
        $ids = $this->grantable($data['permissions'], $own);

        return DB::transaction(function () use ($s, $data, $ids) {
            abort_if($this->nameTaken($s, $data['name']), 422, 'Nama role sudah dipakai.');
            $id = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $id, 'tenant_id' => $s->tenantId, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $id, 'permission_id' => $p], $ids));
            $s->event('access.role_created', $id, ['name' => $data['name'], 'permissions' => $data['permissions']]);

            return $s->find('roles', $id);
        }, 3);
    }

    public function update(TenantStore $s, string $id, array $data, array $own): object
    {
        $ids = $this->grantable($data['permissions'], $own);

        return DB::transaction(function () use ($s, $id, $data, $ids) {
            $role = $s->find('roles', $id, true);
            abort_if($role->is_system, 409, 'Role sistem tidak dapat diubah; buat role kustom.');
            abort_if($this->nameTaken($s, $data['name'], $id), 422, 'Nama role sudah dipakai.');
            DB::table('roles')->where('id', $id)->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'updated_at' => now()]);
            DB::table('role_permissions')->where('role_id', $id)->delete();
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $id, 'permission_id' => $p], $ids));
            $s->event('access.role_updated', $id, ['name' => $data['name'], 'permissions' => $data['permissions']]);

            return $s->find('roles', $id);
        }, 3);
    }

    public function delete(TenantStore $s, string $id): void
    {
        DB::transaction(function () use ($s, $id) {
            $role = $s->find('roles', $id, true);
            abort_if($role->is_system, 409, 'Role sistem tidak dapat dihapus.');
            abort_if(DB::table('membership_roles')->where('role_id', $id)->exists(), 409, 'Role masih dipakai anggota.');
            DB::table('roles')->where('id', $id)->delete();
            $s->event('access.role_deleted', $id, ['name' => $role->name]);
        }, 3);
    }

    /** @param list<string> $roleIds */
    public function assign(TenantStore $s, int $membershipId, array $roleIds, array $own): void
    {
        DB::transaction(function () use ($s, $membershipId, $roleIds, $own) {
            $membership = $s->query('memberships')->where('id', $membershipId)->lockForUpdate()->first();
            abort_unless($membership, 404);
            abort_if($membership->user_id === $s->actorId, 403, 'Anda tidak dapat mengubah role Anda sendiri.');
            $roles = $s->query('roles')->whereIn('id', $roleIds)->pluck('id')->all();
            abort_unless(count($roles) === count(array_unique($roleIds)), 422, 'Role tidak ditemukan di tenant ini.');
            $codes = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereIn('rp.role_id', $roles)->distinct()->pluck('p.code')->all();
            abort_unless(array_diff($codes, $own) === [], 403, 'Anda hanya dapat memberikan izin yang Anda miliki.');
            $current = Access::permissionsFor($membershipId);
            abort_unless(array_diff($current, $own) === [], 403, 'Anggota ini memiliki izin di luar kewenangan Anda.');
            abort_if(in_array('supplier.portal', $codes, true) !== ($membership->supplier_id !== null), 422, 'Akses portal pemasok hanya untuk keanggotaan pemasok, dan keanggotaan pemasok hanya untuk portal pemasok.');
            DB::table('membership_roles')->where('membership_id', $membershipId)->delete();
            DB::table('membership_roles')->insert(array_map(fn ($r) => ['membership_id' => $membershipId, 'role_id' => $r], $roles));
            DB::table('memberships')->where('id', $membershipId)->update(['roles_bootstrapped' => true]);
            // audit subject ids are UUIDs; memberships use integer keys, so the tenant is the subject and the membership is in the metadata.
            $s->event('access.member_roles_changed', $s->tenantId, ['membership_id' => $membershipId, 'user_id' => $membership->user_id, 'role_ids' => $roles]);
        }, 3);
    }

    /** @return list<string> permission ids, after checking each code exists and is held by the actor */
    private function grantable(array $codes, array $own): array
    {
        abort_unless(array_diff($codes, $own) === [], 403, 'Anda hanya dapat memberikan izin yang Anda miliki.');
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();
        abort_unless(count($ids) === count(array_unique($codes)), 422, 'Izin tidak dikenal.');

        return $ids;
    }

    private function nameTaken(TenantStore $s, string $name, ?string $except = null): bool
    {
        return $s->query('roles')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
    }
}
