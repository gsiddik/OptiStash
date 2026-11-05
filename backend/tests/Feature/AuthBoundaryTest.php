<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optistash.deployment_mode' => 'saas', 'optistash.identity_mode' => 'local']);
        $this->tenant = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $this->tenant, 'slug' => 'example', 'name' => 'Example']);
        $user = User::factory()->create(['email' => 'user@example.test', 'password' => Hash::make('Test-only-password!')]);
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $user->id, 'role' => 'warehouse_manager']);
    }

    public function test_anonymous_access_is_rejected(): void
    {
        $this->getJson('/api/v1/master/schema')->assertUnauthorized();
    }

    public function test_login_binds_tenant_and_logout_revokes_access(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.test', 'password' => 'Test-only-password!', 'tenant' => 'example'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant.slug', 'example')->assertJsonPath('data.role', 'warehouse_manager');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/master/schema')->assertUnauthorized();
    }

    public function test_wrong_credentials_and_tenant_have_same_error(): void
    {
        $a = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.test', 'password' => 'incorrect', 'tenant' => 'example'])->assertUnprocessable()->json('errors.email');
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'user@example.test', 'password' => 'Test-only-password!', 'tenant' => 'unknown'])->assertUnprocessable()->json('errors.email');
        $this->assertSame($a, $b);
    }

    public function test_revoked_member_can_still_log_out(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.test', 'password' => 'Test-only-password!', 'tenant' => 'example'])->assertOk();
        DB::table('memberships')->update(['active' => false]);
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
    }
}
