<?php

namespace App\Domain\Platform;

use App\Domain\Access\SystemRoles;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Superadmin operations on tenants. Every change is written to the platform audit log in the same transaction. */
class TenantAdministration
{
    public function __construct(private readonly SystemRoles $roles) {}

    public function create(int $actorId, array $data): object
    {
        if (config('optistash.deployment_mode') === 'standalone') {
            abort_unless($data['slug'] === config('optistash.standalone_tenant'), 422, 'Mode standalone hanya melayani tenant '.config('optistash.standalone_tenant').'.');
        }

        return DB::transaction(function () use ($actorId, $data) {
            abort_if(DB::table('tenants')->where('slug', $data['slug'])->exists(), 422, 'Slug tenant sudah dipakai.');
            $id = (string) Str::uuid();
            DB::table('tenants')->insert(['id' => $id, 'slug' => $data['slug'], 'name' => $data['name'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->roles->syncPermissions();
            $this->roles->syncTenant($id);
            PlatformAccess::audit($actorId, 'platform.tenant_created', $id, ['slug' => $data['slug'], 'name' => $data['name']]);

            return DB::table('tenants')->where('id', $id)->first();
        }, 3);
    }

    public function setActive(int $actorId, string $tenantId, bool $active, string $reason): object
    {
        return DB::transaction(function () use ($actorId, $tenantId, $active, $reason) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            abort_unless($tenant, 404);
            DB::table('tenants')->where('id', $tenantId)->update(['active' => $active, 'updated_at' => now()]);
            PlatformAccess::audit($actorId, $active ? 'platform.tenant_activated' : 'platform.tenant_suspended', $tenantId, ['reason' => $reason]);

            return DB::table('tenants')->where('id', $tenantId)->first();
        }, 3);
    }

    public function linkOptinexus(int $actorId, string $tenantId, ?string $nexusTenantId): object
    {
        return DB::transaction(function () use ($actorId, $tenantId, $nexusTenantId) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            abort_unless($tenant, 404);
            abort_if($nexusTenantId !== null && DB::table('tenants')->where('optinexus_tenant_id', $nexusTenantId)->where('id', '!=', $tenantId)->exists(), 422, 'Tenant OptiNexus ini sudah ditautkan ke tenant lain.');
            DB::table('tenants')->where('id', $tenantId)->update(['optinexus_tenant_id' => $nexusTenantId, 'updated_at' => now()]);
            PlatformAccess::audit($actorId, 'platform.tenant_optinexus_linked', $tenantId, ['from' => $tenant->optinexus_tenant_id, 'to' => $nexusTenantId]);

            return DB::table('tenants')->where('id', $tenantId)->first();
        }, 3);
    }

    /** Creates a new user as administrator of the tenant, or makes an existing member its administrator. */
    public function createAdministrator(int $actorId, string $tenantId, array $data): object
    {
        return DB::transaction(function () use ($actorId, $tenantId, $data) {
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            abort_unless($tenant, 404);
            $this->roles->syncPermissions();
            $this->roles->syncTenant($tenantId);
            $email = strtolower($data['email']);
            abort_if(User::where('email', $email)->exists(), 422, 'Email sudah terdaftar; gunakan email lain.');
            $user = User::create(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
            $membershipId = DB::table('memberships')->insertGetId(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => 'tenant_administrator', 'active' => true, 'roles_bootstrapped' => true]);
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('name', 'tenant_administrator')->value('id');
            DB::table('membership_roles')->insert(['membership_id' => $membershipId, 'role_id' => $roleId]);
            PlatformAccess::audit($actorId, 'platform.tenant_admin_created', $tenantId, ['user_id' => $user->id, 'email' => $email]);

            return (object) ['user_id' => $user->id, 'email' => $email, 'tenant_id' => $tenantId];
        }, 3);
    }
}
