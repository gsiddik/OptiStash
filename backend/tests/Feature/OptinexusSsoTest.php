<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\SystemRoles;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** OptiNexus identity mode against a faked OptiNexus that signs real RS256 tokens. */
class OptinexusSsoTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://nexus.test';

    private const NEXUS_TENANT = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private const SUBJECT = '9a8b7c6d-2222-4e5f-9a0b-abcdefabcdef';

    private string $privateKey = '';

    /** @var array<string,mixed> */
    private array $jwk;

    private string $tenantId;

    /** @var array<string,mixed> */
    private array $grant = ['allowed' => true, 'permissions' => ['optistash.wms.access', 'optistash.master.manage', 'optistash.not.in.catalog', 'optifleet.vehicles.view', '*']];

    /** @var array<string,mixed> extra id_token claims for the next sign-in */
    private array $claims = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'optistash.deployment_mode' => 'saas',
            'optistash.identity_mode' => 'optinexus',
            'optistash.optinexus.base_url' => self::ISSUER,
            'optistash.optinexus.sso.client_id' => 'optistash-web',
            'optistash.optinexus.sso.client_secret' => 'sso-secret',
            'optistash.optinexus.sso.frontend_url' => 'https://stash.test',
            'optistash.optinexus.service.client_id' => 'optistash-sa',
            'optistash.optinexus.service.client_secret' => 'sa-secret',
        ]);
        app(SystemRoles::class)->syncPermissions();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];
        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $this->tenantId, 'slug' => 'acme', 'name' => 'Acme', 'active' => true, 'optinexus_tenant_id' => self::NEXUS_TENANT, 'created_at' => now(), 'updated_at' => now()]);
        $this->fakeNexus();
    }

    private function sign(array $claims, string $typ = 'JWT'): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', 'k1', ['typ' => $typ]);
    }

    private function fakeNexus(): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if ($this->outage) {
                return Http::response([], 503);
            }

            return match (true) {
                str_ends_with($url, '/.well-known/openid-configuration') => Http::response(['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/oidc/authorize', 'token_endpoint' => self::ISSUER.'/oidc/token', 'userinfo_endpoint' => self::ISSUER.'/oidc/userinfo', 'jwks_uri' => self::ISSUER.'/oidc/jwks', 'end_session_endpoint' => self::ISSUER.'/oidc/logout']),
                str_ends_with($url, '/oidc/jwks') => Http::response(['keys' => [$this->jwk]]),
                str_ends_with($url, '/oidc/token') => Http::response(['access_token' => 'user-at', 'id_token' => $this->sign([
                    'iss' => self::ISSUER, 'aud' => 'optistash-web', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $this->nonce,
                    'sub' => self::SUBJECT, 'email' => 'rina@acme.test', 'email_verified' => true, 'name' => 'Rina', 'sid' => 'sid-1',
                    'tenant_id' => self::NEXUS_TENANT, 'apps' => [['code' => 'optistash', 'name' => 'OptiStash', 'launch_url' => 'https://stash.test']],
                    ...$this->claims,
                ])]),
                str_ends_with($url, '/oidc/userinfo') => Http::response(['sub' => $this->claims['sub'] ?? self::SUBJECT, 'tenant_id' => self::NEXUS_TENANT]),
                str_ends_with($url, '/api/v1/oauth/token') => Http::response(['access_token' => 'sa-token', 'expires_in' => 3600]),
                str_ends_with($url, '/api/v1/authorization/effective-permissions') => Http::response(['success' => true, 'data' => [
                    'schema_version' => '1.0', 'user_id' => $request['user_id'], 'tenant_id' => $request['tenant_id'], 'application_code' => 'optistash',
                    'allowed' => $this->grant['allowed'], 'deny_reason' => null, 'ttl_seconds' => 300,
                    'entitled_capabilities' => array_map(fn ($c) => ['code' => $c, 'type' => 'MODULE'], $this->grant['entitled'] ?? []),
                    'permissions' => array_map(fn ($p) => ['permission' => $p, 'scope' => 'TENANT'], $this->grant['permissions']),
                ]]),
                default => Http::response([], 404),
            };
        });
    }

    private string $nonce = '';

    private bool $outage = false;

    /** Runs the browser part of a sign-in and returns where the callback sent the browser. */
    private function signIn(): string
    {
        $authorize = $this->get('/api/v1/auth/sso/redirect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/oidc/authorize?', $authorize);
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->nonce = $query['nonce'];

        return (string) $this->get('/api/v1/auth/sso/callback?'.http_build_query(['code' => 'c1', 'state' => $query['state'], 'iss' => self::ISSUER]))->headers->get('Location');
    }

    private function logoutToken(array $events = [], array $extra = []): string
    {
        return $this->sign(['iss' => self::ISSUER, 'aud' => 'optistash-web', 'iat' => time(), 'exp' => time() + 120, 'jti' => (string) Str::uuid(), 'sub' => self::SUBJECT,
            'events' => [OidcClient::EVENT_BACKCHANNEL_LOGOUT => (object) [], ...$events], ...$extra], 'logout+jwt');
    }

    public function test_sign_in_links_the_user_and_takes_permissions_only_from_optinexus(): void
    {
        $this->grant['permissions'][] = 'optistash.access.role.manage';
        $this->assertSame('https://stash.test/', $this->signIn());
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(['access.role.manage', 'master.manage', 'wms.access'], $me->json('data.permissions'));
        $this->assertSame('optinexus', $me->json('data.identity_mode'));
        $this->assertDatabaseHas('users', ['email' => 'rina@acme.test', 'optinexus_subject' => self::SUBJECT]);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $this->tenantId, 'action' => 'auth.sso_login']);
        $this->getJson('/api/v1/master/schema')->assertOk();
        // Roles are not a second authority in this mode.
        $this->postJson('/api/v1/access/roles', ['name' => 'Lokal', 'permissions' => ['wms.access']])->assertStatus(409);
    }

    public function test_a_state_is_single_use_and_an_unlinked_tenant_is_refused(): void
    {
        $authorize = $this->get('/api/v1/auth/sso/redirect')->headers->get('Location');
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);
        $this->nonce = $query['nonce'];
        $this->get('/api/v1/auth/sso/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]));
        $again = $this->get('/api/v1/auth/sso/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]))->headers->get('Location');
        $this->assertSame('https://stash.test/?sso_error=state_invalid', $again);

        $this->postJson('/api/v1/auth/logout');
        DB::table('tenants')->update(['optinexus_tenant_id' => null]);
        $this->assertSame('https://stash.test/?sso_error=tenant_not_linked', $this->signIn());
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refusal_or_no_permission_in_optinexus_means_no_session(): void
    {
        $this->grant = ['allowed' => false, 'permissions' => ['optistash.wms.access']];
        $this->assertSame('https://stash.test/?sso_error=no_permissions', $this->signIn());
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_an_unverified_email_never_claims_an_existing_account(): void
    {
        User::factory()->create(['email' => 'rina@acme.test']);
        $this->claims = ['email_verified' => false];
        $this->assertSame('https://stash.test/?sso_error=email_not_verified', $this->signIn());
    }

    public function test_tenant_password_login_is_closed_but_status_tells_the_spa(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x', 'tenant' => 'acme'])->assertForbidden();
        $this->getJson('/api/v1/auth/sso/status')->assertOk()->assertJson(['data' => ['sso_enabled' => true, 'password_login' => false]]);
    }

    public function test_backchannel_logout_ends_the_session_and_refuses_bad_or_replayed_tokens(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $this->sign(['iss' => self::ISSUER, 'aud' => 'optistash-web', 'iat' => time(), 'sub' => self::SUBJECT, 'jti' => 'x', 'events' => [OidcClient::EVENT_BACKCHANNEL_LOGOUT => (object) []]])])->assertStatus(400);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $token = $this->logoutToken(extra: ['session_ids' => ['sid-1']]);
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $token])->assertOk();
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $token])->assertStatus(400);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_access_revoked_for_a_tenant_deactivates_the_membership_until_the_next_sign_in(): void
    {
        $this->signIn();
        $revoked = [OidcClient::EVENT_ACCESS_REVOKED => ['reason' => 'tenant_membership_removed', 'scope' => 'tenant', 'tenant_id' => self::NEXUS_TENANT]];
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $this->logoutToken($revoked)])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $membership = DB::table('memberships')->where('tenant_id', $this->tenantId)->first();
        $this->assertFalse((bool) $membership->active);
        $this->assertNotNull($membership->optinexus_deactivated_at);

        $this->signIn();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue((bool) DB::table('memberships')->where('id', $membership->id)->value('active'));
    }

    public function test_access_revoked_for_the_user_deactivates_the_account_and_unknown_users_are_accepted(): void
    {
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $this->logoutToken()])->assertOk();

        $this->signIn();
        $revoked = [OidcClient::EVENT_ACCESS_REVOKED => ['reason' => 'user_suspended', 'scope' => 'user']];
        $this->post('/api/v1/integration/optinexus/backchannel-logout', ['logout_token' => $this->logoutToken($revoked)])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('users', ['optinexus_subject' => self::SUBJECT, 'active' => false]);
    }

    public function test_an_outage_fails_closed_once_the_cached_answer_is_gone(): void
    {
        $this->signIn();
        $this->outage = true;
        cache()->flush();
        $this->getJson('/api/v1/master/schema')->assertStatus(503);
    }

    public function test_sign_out_returns_the_optinexus_logout_address(): void
    {
        $this->signIn();
        $url = $this->postJson('/api/v1/auth/logout')->assertOk()->json('data.logout_url');
        $this->assertStringStartsWith(self::ISSUER.'/oidc/logout?', $url);
        $this->assertStringContainsString('id_token_hint=', $url);
    }

    public function test_a_module_permission_needs_the_module_entitlement(): void
    {
        $this->grant['permissions'] = ['optistash.wms.access', 'optistash.inventory.analysis.configure', 'optistash.partner.provision'];
        $this->signIn();
        $this->assertSame(['wms.access'], $this->getJson('/api/v1/auth/me')->json('data.permissions'));

        $this->postJson('/api/v1/auth/logout');
        cache()->flush();
        $this->grant['entitled'] = ['optistash.analysis'];
        $this->signIn();
        $this->assertSame(['inventory.analysis.configure', 'wms.access'], $this->getJson('/api/v1/auth/me')->json('data.permissions'));
    }

    public function test_manifest_lists_every_permission_under_the_application_namespace(): void
    {
        $this->artisan('optistash:nexus:manifest', ['--output' => $file = tempnam(sys_get_temp_dir(), 'manifest')])->assertSuccessful();
        $manifest = json_decode((string) file_get_contents($file), true);
        unlink($file);
        $this->assertSame('2.0', $manifest['manifest_version']);
        $keys = array_column($manifest['permissions'], 'permission_key');
        $this->assertCount(count(PermissionCatalog::codes()), $keys);
        foreach ($manifest['permissions'] as $permission) {
            $this->assertStringStartsWith('optistash.', $permission['permission_key']);
        }
        $byKey = array_column($manifest['permissions'], null, 'permission_key');
        $this->assertSame('optistash.analysis', $byKey['optistash.inventory.analysis.configure']['capability_code']);
        $this->assertNull($byKey['optistash.wms.access']['capability_code']);
        $this->assertFalse($byKey['optistash.supplier.portal']['delegable']);
        $this->assertSame(['optistash.analysis', 'optistash.quality', 'optistash.iot', 'optistash.supplier_portal'], array_column($manifest['capabilities'], 'code'));
        $this->assertStringEndsWith('/api/v1/integration/optinexus/backchannel-logout', $manifest['oidc_client']['backchannel_logout_uri']);
    }
}
