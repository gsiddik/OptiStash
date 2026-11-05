<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait SignsInTenantMember
{
    /** Creates an active tenant + member, signs them in through the session and returns the tenant id. */
    protected function signInTenantMember(string $role = 'warehouse_manager', string $slug = 'test'): string
    {
        config(['optistash.deployment_mode' => 'saas', 'optistash.identity_mode' => 'local']);
        $tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'slug' => $slug, 'name' => ucfirst($slug)]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $tenant, 'user_id' => $user->id, 'role' => $role, 'active' => true]);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant]);

        return $tenant;
    }
}
