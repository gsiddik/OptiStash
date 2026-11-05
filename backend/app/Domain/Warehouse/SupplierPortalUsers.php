<?php

namespace App\Domain\Warehouse;

use App\Domain\Shared\TenantStore;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
