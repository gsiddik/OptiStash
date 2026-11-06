<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class SupplierInvitationTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private string $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('tenant_administrator', 'gudang');
        $this->supplier = $this->postJson('/api/v1/master/partners', ['code' => 'SUP1', 'name' => 'Pemasok Satu', 'type' => 'SUPPLIER'])->assertCreated()->json('data.id');
    }

    private function sentTokens(): array
    {
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
        $out = [];
        foreach ($messages as $m) {
            preg_match('#/\?invite=([A-Za-z0-9]{48})#', quoted_printable_decode($m->getOriginalMessage()->getBody()->bodyToString()), $hit);
            $out[] = ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'token' => $hit[1] ?? null];
        }

        return $out;
    }

    public function test_an_invited_person_chooses_their_own_password_once(): void
    {
        $this->postJson("/api/v1/partners/{$this->supplier}/invitations", ['name' => 'Budi', 'email' => 'Budi@Pemasok.test'])->assertCreated()->assertJsonPath('data.email', 'budi@pemasok.test');
        $this->getJson("/api/v1/partners/{$this->supplier}/invitations")->assertOk()->assertJsonCount(1, 'data');
        [$mail] = $this->sentTokens();
        $this->assertSame('budi@pemasok.test', $mail['to']);
        $token = $mail['token'];
        $this->assertNotNull($token);
        // Only the hash is stored.
        $this->assertSame(0, DB::table('supplier_invitations')->where('token_hash', $token)->count());

        auth()->logout();
        $this->flushSession();
        $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('data.supplier', 'Pemasok Satu')->assertJsonPath('data.email', 'budi@pemasok.test');
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'lemah', 'password_confirmation' => 'lemah'])->assertUnprocessable();
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Kata-Sandi-Kuat-1!', 'password_confirmation' => 'beda'])->assertUnprocessable();
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Kata-Sandi-Kuat-1!', 'password_confirmation' => 'Kata-Sandi-Kuat-1!'])->assertCreated()->assertJsonPath('data.tenant', 'gudang');

        $user = User::where('email', 'budi@pemasok.test')->firstOrFail();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'tenant_id' => $this->tenant, 'role' => 'supplier', 'supplier_id' => $this->supplier]);
        // Used once only.
        $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['password' => 'Kata-Sandi-Kuat-1!', 'password_confirmation' => 'Kata-Sandi-Kuat-1!'])->assertNotFound();
    }

    public function test_expired_revoked_and_replaced_invitations_do_not_work(): void
    {
        $this->postJson("/api/v1/partners/{$this->supplier}/invitations", ['name' => 'Budi', 'email' => 'budi@pemasok.test'])->assertCreated();
        $this->postJson("/api/v1/partners/{$this->supplier}/invitations", ['name' => 'Budi', 'email' => 'budi@pemasok.test'])->assertCreated();
        [$first, $second] = array_column($this->sentTokens(), 'token');
        // The newer invitation replaces the older one.
        $this->getJson("/api/v1/auth/invitations/{$first}")->assertNotFound();
        $this->getJson("/api/v1/auth/invitations/{$second}")->assertOk();
        $this->getJson("/api/v1/partners/{$this->supplier}/invitations")->assertJsonCount(1, 'data');

        DB::table('supplier_invitations')->where('token_hash', hash('sha256', $second))->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/auth/invitations/{$second}")->assertNotFound();

        $this->postJson("/api/v1/partners/{$this->supplier}/invitations", ['name' => 'Budi', 'email' => 'budi@pemasok.test'])->assertCreated();
        $id = $this->getJson("/api/v1/partners/{$this->supplier}/invitations")->json('data.0.id');
        $this->deleteJson("/api/v1/partners/{$this->supplier}/invitations/{$id}")->assertNoContent();
        $this->getJson("/api/v1/partners/{$this->supplier}/invitations")->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/partners/{$this->supplier}/invitations/{$id}")->assertNotFound();
    }

    public function test_only_a_supplier_with_a_new_email_can_be_invited_by_someone_allowed(): void
    {
        User::factory()->create(['email' => 'ada@pemasok.test']);
        $this->postJson("/api/v1/partners/{$this->supplier}/invitations", ['name' => 'Ada', 'email' => 'ada@pemasok.test'])->assertUnprocessable();
        $customer = $this->postJson('/api/v1/master/partners', ['code' => 'CUS1', 'name' => 'Pelanggan', 'type' => 'CUSTOMER'])->json('data.id');
        $this->postJson("/api/v1/partners/{$customer}/invitations", ['name' => 'Cici', 'email' => 'cici@x.test'])->assertUnprocessable();
    }

    public function test_production_refuses_to_invite_without_a_real_mailer(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);
        try {
            (new \App\Domain\Warehouse\SupplierPortalUsers(new \App\Domain\Shared\TenantStore($this->tenant, 1)))->invite($this->supplier, 'Budi', 'budi@pemasok.test');
            $this->fail('Expected a 503.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('supplier_invitations')->count());
    }

    public function test_an_administrator_can_still_add_an_account_directly(): void
    {
        $this->postJson("/api/v1/partners/{$this->supplier}/portal-users", ['name' => 'Eka', 'email' => 'eka@pemasok.test', 'password' => 'Kata-Sandi-Kuat-1!'])->assertCreated();
        $this->getJson("/api/v1/partners/{$this->supplier}/portal-users")->assertJsonCount(1, 'data');
    }
}
