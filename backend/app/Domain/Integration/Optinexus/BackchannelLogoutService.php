<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Platform\PlatformAccess;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a verified OptiNexus logout token (docs/architecture/OPTINEXUS_ADAPTER.md §5). A logout ends the named OIDC sessions,
 * or, without session ids, every session of the user (the session epoch is raised). Access revoked ends every session and deactivates the
 * membership of the named tenant (scope tenant) or the account (scope user), marked as done by OptiNexus so the next
 * successful sign-in undoes exactly that. An unknown user is not an error.
 */
class BackchannelLogoutService
{
    /** @param  array<string,mixed>  $claims  verified logout token claims */
    public function apply(array $claims): void
    {
        $user = User::query()->where('optinexus_subject', (string) $claims['sub'])->first();
        if (! $user) {
            return;
        }

        $revoked = $claims['events'][OidcClient::EVENT_ACCESS_REVOKED] ?? null;

        if (! is_array($revoked) && isset($claims['session_ids'])) {
            // Sessions are checked against this list by TenantContext for as long as a session may live.
            foreach ($claims['session_ids'] as $sid) {
                Cache::put(self::revokedSidKey($sid), true, now()->addMinutes(max(1, (int) config('session.lifetime'))));
            }
            PlatformAccess::audit(null, 'auth.sso_logout', (string) $user->id, ['optinexus_subject' => $user->optinexus_subject, 'sessions' => count($claims['session_ids'])]);

            return;
        }

        DB::transaction(function () use ($user, $revoked) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $locked->forceFill(['session_epoch' => $locked->session_epoch + 1])->save();

            if (! is_array($revoked)) {
                PlatformAccess::audit(null, 'auth.sso_logout', (string) $user->id, ['optinexus_subject' => $user->optinexus_subject]);

                return;
            }

            $reason = Str::limit((string) ($revoked['reason'] ?? 'access_revoked'), 64, '');
            $scope = ($revoked['scope'] ?? 'user') === 'tenant' ? 'tenant' : 'user';
            $memberships = DB::table('memberships')->where('user_id', $user->id)->where('active', true);

            if ($scope === 'tenant') {
                $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($revoked['tenant_id'] ?? ''))->first();
                if (! $tenant) {
                    return;
                }
                $memberships->where('tenant_id', $tenant->id);
            } elseif ($locked->active) {
                $locked->forceFill(['active' => false, 'optinexus_deactivated_at' => now()])->save();
            }

            foreach ($memberships->get(['id', 'tenant_id']) as $m) {
                NexusPermissionSource::forget($m->tenant_id, $m->id);
                if ($scope === 'tenant') {
                    DB::table('memberships')->where('id', $m->id)->update(['active' => false, 'optinexus_deactivated_at' => now()]);
                }
                DB::table('audit_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $m->tenant_id, 'actor_id' => $user->id, 'action' => 'auth.sso_access_revoked', 'subject_id' => $m->tenant_id, 'metadata' => json_encode(['membership_id' => $m->id, 'reason' => $reason, 'scope' => $scope], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
        });
    }

    public static function revokedSidKey(string $sid): string
    {
        return 'optinexus.revoked_sid.'.hash('sha256', $sid);
    }
}
