<?php

namespace App\Domain\Access;

use App\Domain\Platform\PlatformAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bootstrap data only: the permission registry, each tenant's system roles, and the first role assignment of a membership
 * from its legacy `role` label. Nothing authorizes by role name; roles are permission collections.
 */
class SystemRoles
{
    public function syncPermissions(): void
    {
        foreach (PermissionCatalog::rows() as $row) {
            $existing = DB::table('permissions')->where('code', $row['code'])->first();
            $existing
                ? DB::table('permissions')->where('id', $existing->id)->update(['group' => $row['group'], 'description' => $row['description']])
                : DB::table('permissions')->insert([...$row, 'id' => (string) Str::uuid()]);
        }
    }

    /** Creates missing system roles of a tenant and tops up their permissions from the catalog (never removes a grant). */
    public function syncTenant(string $tenantId): void
    {
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        foreach (array_keys(PermissionCatalog::SYSTEM_ROLES) as $name) {
            DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => $name, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('name', $name)->value('id');
            $rows = collect(PermissionCatalog::templateCodes($name))->filter(fn ($code) => isset($permissionIds[$code]))
                ->map(fn ($code) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$code]])->values()->all();
            DB::table('role_permissions')->insertOrIgnore($rows);
        }
    }

    public function syncAll(): void
    {
        PlatformAccess::sync();
        $this->syncTenants();
    }

    /** Tenant side only: permission registry, system roles of every tenant and pending first role assignments. */
    public function syncTenants(): void
    {
        $this->syncPermissions();
        DB::table('tenants')->pluck('id')->each(fn ($id) => $this->syncTenant($id));
        DB::table('memberships')->where('roles_bootstrapped', false)->get()->each(fn ($m) => $this->bootstrapMembership($m));
    }

    /** First assignment only: gives a new membership the system role named by its legacy label, once. */
    public function bootstrapMembership(object $membership): void
    {
        DB::transaction(function () use ($membership) {
            $locked = DB::table('memberships')->where('id', $membership->id)->lockForUpdate()->first();
            if (! $locked || $locked->roles_bootstrapped) {
                return;
            }
            if (! DB::table('roles')->where('tenant_id', $locked->tenant_id)->where('is_system', true)->exists()) {
                $this->syncPermissions();
                $this->syncTenant($locked->tenant_id);
            }
            $roleId = DB::table('roles')->where('tenant_id', $locked->tenant_id)->where('is_system', true)->where('name', $locked->role)->value('id');
            if ($roleId) {
                DB::table('membership_roles')->insertOrIgnore(['membership_id' => $locked->id, 'role_id' => $roleId]);
            }
            DB::table('memberships')->where('id', $locked->id)->update(['roles_bootstrapped' => true]);
        });
    }
}
