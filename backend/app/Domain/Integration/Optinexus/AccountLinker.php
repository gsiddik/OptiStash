<?php

namespace App\Domain\Integration\Optinexus;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Turns verified OptiNexus claims into the local user and membership (docs/architecture/OPTINEXUS_ADAPTER.md §3).
 * OptiNexus decides who may enter and what they may do; this class keeps only the local projection. The tenant comes from
 * the verified `tenant_id` claim and must already be linked by a Superadmin; it is never created from a claim.
 */
class AccountLinker
{
    public function __construct(private readonly NexusPermissionSource $permissions) {}

    /**
     * @param  array<string,mixed>  $claims  verified id_token claims
     * @return array{0:User,1:object,2:object} user, tenant row, membership row
     *
     * @throws SsoException
     * @throws IdentityProviderUnavailable
     */
    public function link(array $claims): array
    {
        $apps = array_merge(array_column((array) ($claims['apps'] ?? []), 'code'), (array) ($claims['groups'] ?? []));
        if (! in_array(OptinexusSettings::applicationCode(), $apps, true)) {
            throw new SsoException('access_denied');
        }

        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) $claims['tenant_id'])->first();
        if (! $tenant) {
            throw new SsoException('tenant_not_linked');
        }
        if (! $tenant->active || (config('optistash.deployment_mode') === 'standalone' && $tenant->slug !== config('optistash.standalone_tenant'))) {
            throw new SsoException('tenant_inactive');
        }

        // What OptiNexus lets this person do here. A person with no permission has nothing to do in the app.
        $pulled = $this->permissions->pull((string) $claims['tenant_id'], (string) $claims['sub']);
        if ($pulled['permissions'] === []) {
            throw new SsoException('no_permissions');
        }

        [$user, $membership] = DB::transaction(function () use ($claims, $tenant) {
            $user = $this->user($claims);
            $membership = $this->membership($user, $tenant);
            DB::table('audit_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'actor_id' => $user->id, 'action' => 'auth.sso_login', 'subject_id' => $tenant->id, 'metadata' => json_encode(['optinexus_subject' => $claims['sub'], 'membership_id' => $membership->id], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return [$user, $membership];
        });

        $this->permissions->prime($tenant->id, $membership->id, $pulled['permissions'], $pulled['ttl']);

        return [$user, $tenant, $membership];
    }

    /** @throws SsoException */
    private function user(array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = ($claims['email_verified'] ?? false) === true;
        $user = User::query()->where('optinexus_subject', $subject)->lockForUpdate()->first();

        if (! $user) {
            if ($email === '') {
                throw new SsoException('email_required');
            }
            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
            if ($existing) {
                // Same e-mail: only a verified one may claim an unlinked account that is not a platform operator; never re-bind.
                if (! $verified) {
                    throw new SsoException('email_not_verified');
                }
                if ($existing->optinexus_subject !== null || DB::table('platform_role_assignments')->where('user_id', $existing->id)->exists()) {
                    throw new SsoException('account_conflict');
                }
                $existing->forceFill(['optinexus_subject' => $subject])->save();
                $user = $existing;
            } else {
                // The password is random and never shown: this identity signs in at OptiNexus only.
                $user = new User(['name' => Str::limit((string) ($claims['name'] ?? $email), 250, ''), 'email' => $email, 'password' => Hash::make(Str::random(64))]);
                $user->forceFill(['optinexus_subject' => $subject, 'active' => true, 'session_epoch' => 0])->save();
            }
        }

        // Keep the projection attached to the immutable subject when OptiNexus changes an e-mail.
        if ($email !== '' && strtolower($user->email) !== $email) {
            if (! $verified) {
                throw new SsoException('email_not_verified');
            }
            if (User::query()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw new SsoException('account_conflict');
            }
            $user->forceFill(['email' => $email])->save();
        }

        // OptiNexus has just let this person in: undo exactly what an earlier OptiNexus event switched off.
        if (! $user->active && $user->optinexus_deactivated_at !== null) {
            $user->forceFill(['active' => true, 'optinexus_deactivated_at' => null])->save();
        }
        if (! $user->active) {
            throw new SsoException('user_inactive');
        }

        return $user;
    }

    /** @throws SsoException */
    private function membership(User $user, object $tenant): object
    {
        $membership = DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->lockForUpdate()->first();

        if (! $membership) {
            // Roles are not copied: permissions are whatever OptiNexus answers. `role` keeps a label for old screens only.
            DB::table('memberships')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'optinexus', 'active' => true, 'roles_bootstrapped' => true]);

            return DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        }

        if (! $membership->active && $membership->optinexus_deactivated_at !== null) {
            DB::table('memberships')->where('id', $membership->id)->update(['active' => true, 'optinexus_deactivated_at' => null]);
            $membership = DB::table('memberships')->where('id', $membership->id)->first();
        }
        if (! $membership->active) {
            throw new SsoException('membership_inactive');
        }

        return $membership;
    }
}
