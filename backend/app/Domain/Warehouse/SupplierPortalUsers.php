<?php

namespace App\Domain\Warehouse;

use App\Domain\Shared\TenantStore;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Portal accounts of a supplier. A supplier's user belongs to exactly one supplier partner of the tenant (memberships.supplier_id) and
 * never reaches the internal screens. An existing account is never taken over: the email must be new.
 */
final class SupplierPortalUsers
{
    public function __construct(private readonly TenantStore $store) {}

    public function list(string $partnerId): array
    {
        $this->supplier($partnerId);

        return DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $this->store->tenantId)->where('memberships.supplier_id', $partnerId)
            ->orderBy('users.email')->get(['memberships.id as membership_id', 'users.name', 'users.email', 'memberships.active'])->all();
    }

    private function supplier(string $partnerId): object
    {
        $partner = $this->store->find('partners', $partnerId);
        abort_unless($partner->type === 'SUPPLIER', 422, 'Hanya mitra berjenis pemasok yang dapat memiliki akun portal.');

        return $partner;
    }

    public function create(string $partnerId, string $name, string $email, string $password): array
    {
        return DB::transaction(function () use ($partnerId, $name, $email, $password) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $partner = $this->supplier($partnerId);
            abort_unless($partner->active, 422, 'Pemasok tidak aktif.');
            $email = strtolower(trim($email));
            abort_if(User::where('email', $email)->exists(), 422, 'Email sudah terdaftar. Gunakan email lain untuk akun portal.');
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $membership = DB::table('memberships')->insertGetId(['tenant_id' => $this->store->tenantId, 'user_id' => $user->id, 'role' => 'supplier', 'active' => true, 'supplier_id' => $partnerId]);
            $this->store->event('partner.portal_user_created', $partnerId, ['membership_id' => $membership, 'user_id' => $user->id, 'email' => $email]);

            return ['membership_id' => $membership, 'name' => $name, 'email' => $email, 'active' => true];
        }, 3);
    }

    public const INVITATION_HOURS = 72;

    /** Pending (not accepted, not revoked, not expired) invitations of a supplier. */
    public function invitations(string $partnerId): array
    {
        $this->supplier($partnerId);

        return $this->store->query('supplier_invitations')->where('partner_id', $partnerId)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('created_at')->get(['id', 'name', 'email', 'expires_at', 'created_at'])->all();
    }

    /**
     * Invites a person of the supplier by email: they get a one-time link (valid 72 hours) and choose their own password, so no password
     * is ever typed by an administrator or sent by mail. A new invitation for the same address replaces the pending one. Needs a real mailer.
     */
    public function invite(string $partnerId, string $name, string $email): array
    {
        abort_if(! app()->environment(['local', 'testing']) && in_array(config('mail.default'), ['log', 'array', 'failover', 'roundrobin'], true), 503, 'Email belum dikonfigurasi pada server ini. Tambahkan akun secara langsung atau hubungi administrator.');
        $email = strtolower(trim($email));
        $token = Str::random(48);
        $id = DB::transaction(function () use ($partnerId, $name, $email, $token) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $partner = $this->supplier($partnerId);
            abort_unless($partner->active, 422, 'Pemasok tidak aktif.');
            abort_if(User::where('email', $email)->exists(), 422, 'Email sudah terdaftar. Gunakan email lain untuk akun portal.');
            $this->store->query('supplier_invitations')->where('partner_id', $partnerId)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('supplier_invitations')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'partner_id' => $partnerId, 'name' => $name, 'email' => $email,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(self::INVITATION_HOURS), 'invited_by' => $this->store->actorId, 'created_at' => now()]);
            $this->store->event('partner.portal_user_invited', $partnerId, ['invitation_id' => $id, 'email' => $email]);

            return $id;
        }, 3);
        $tenant = DB::table('tenants')->where('id', $this->store->tenantId)->value('name');
        $supplier = $this->supplier($partnerId)->name;
        $link = config('optistash.frontend_url').'/?invite='.$token;
        $hours = self::INVITATION_HOURS;
        $body = "Halo {$name},\n\n{$tenant} mengundang Anda memakai portal pemasok OptiStash untuk {$supplier}.\n\nBuka tautan berikut untuk memilih kata sandi Anda (berlaku {$hours} jam, hanya sekali pakai):\n{$link}\n\nJika Anda tidak merasa diundang, abaikan email ini.";
        try {
            Mail::raw($body, fn ($m) => $m->to($email)->subject("Undangan portal pemasok {$tenant}"));
        } catch (\Throwable $e) {
            $this->store->query('supplier_invitations')->where('id', $id)->update(['revoked_at' => now()]);
            Log::warning('Supplier invitation mail failed', ['invitation' => $id, 'error' => $e->getMessage()]);
            abort(503, 'Email undangan gagal dikirim. Coba lagi nanti atau tambahkan akun secara langsung.');
        }

        return ['id' => $id, 'email' => $email, 'expires_at' => now()->addHours(self::INVITATION_HOURS)->toIso8601String()];
    }

    public function revokeInvitation(string $partnerId, string $invitationId): void
    {
        $this->supplier($partnerId);
        $n = $this->store->query('supplier_invitations')->where('id', $invitationId)->where('partner_id', $partnerId)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        abort_if($n === 0, 404);
        $this->store->event('partner.portal_user_invitation_revoked', $partnerId, ['invitation_id' => $invitationId]);
    }

    /** What a person sees before choosing a password. Unknown, used, revoked and expired tokens look the same (404). */
    public static function previewInvitation(string $token): array
    {
        $i = self::openInvitation($token);
        $partner = DB::table('partners')->where('tenant_id', $i->tenant_id)->where('id', $i->partner_id)->first(['name']);

        return ['name' => $i->name, 'email' => $i->email, 'supplier' => $partner->name, 'tenant' => DB::table('tenants')->where('id', $i->tenant_id)->value('name')];
    }

    private static function openInvitation(string $token): object
    {
        $i = DB::table('supplier_invitations')->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($i, 404, 'Undangan tidak ditemukan atau sudah kedaluwarsa.');

        return $i;
    }

    /** Creates the account of the invited person with the password they chose. The invitation works once. */
    public static function acceptInvitation(string $token, string $password): array
    {
        return DB::transaction(function () use ($token, $password) {
            $i = DB::table('supplier_invitations')->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($i, 404, 'Undangan tidak ditemukan atau sudah kedaluwarsa.');
            $tenant = DB::table('tenants')->where('id', $i->tenant_id)->lockForUpdate()->firstOrFail();
            $partner = DB::table('partners')->where('tenant_id', $i->tenant_id)->where('id', $i->partner_id)->first();
            abort_unless($tenant->active && $partner && $partner->active && $partner->type === 'SUPPLIER', 422, 'Undangan tidak lagi berlaku.');
            abort_if(User::where('email', $i->email)->exists(), 422, 'Email sudah terdaftar. Minta undangan dengan email lain.');
            $user = User::create(['name' => $i->name, 'email' => $i->email, 'password' => $password]);
            $membership = DB::table('memberships')->insertGetId(['tenant_id' => $i->tenant_id, 'user_id' => $user->id, 'role' => 'supplier', 'active' => true, 'supplier_id' => $i->partner_id]);
            DB::table('supplier_invitations')->where('id', $i->id)->update(['accepted_at' => now()]);
            $store = new TenantStore($i->tenant_id, $user->id);
            $store->event('partner.portal_user_created', $i->partner_id, ['membership_id' => $membership, 'user_id' => $user->id, 'email' => $i->email, 'via' => 'invitation']);

            return ['email' => $i->email, 'tenant' => $tenant->slug];
        }, 3);
    }

    public function setActive(string $partnerId, int $membershipId, bool $active): void
    {
        DB::transaction(function () use ($partnerId, $membershipId, $active) {
            $this->supplier($partnerId);
            $row = DB::table('memberships')->where('tenant_id', $this->store->tenantId)->where('id', $membershipId)->where('supplier_id', $partnerId)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('memberships')->where('id', $membershipId)->update(['active' => $active]);
            $this->store->event($active ? 'partner.portal_user_activated' : 'partner.portal_user_deactivated', $partnerId, ['membership_id' => $membershipId, 'user_id' => $row->user_id]);
        }, 3);
    }
}
