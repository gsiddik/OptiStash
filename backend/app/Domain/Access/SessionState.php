<?php

namespace App\Domain\Access;

use App\Domain\Integration\Optinexus\BackchannelLogoutService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Starts a tenant session and decides whether it is still valid (account active, not ended by an OptiNexus logout). */
final class SessionState
{
    /** @param  array{sid?:?string,id_token_hint?:?string}  $sso  set only for a sign-in through OptiNexus */
    public static function start(Request $request, User $user, string $tenantId, ?array $sso = null, bool $external = false): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['platform', 'optinexus_sso', 'optinexus_sid', 'optinexus_id_token_hint', 'external_login']);
        $request->session()->put(['tenant_id' => $tenantId, 'session_epoch' => (int) $user->session_epoch]);
        if ($external) {
            // A vendor's or customer's own account, signed in here with its password while the staff sign in through OptiNexus.
            $request->session()->put('external_login', true);
        }
        if ($sso !== null) {
            $request->session()->put(['optinexus_sso' => true, 'optinexus_sid' => $sso['sid'] ?? null, 'optinexus_id_token_hint' => $sso['id_token_hint'] ?? null]);
        }
    }

    public static function valid(Request $request, User $user): bool
    {
        // Read fresh: a revocation from another request must take effect on the very next request.
        $state = DB::table('users')->where('id', $user->id)->first(['active', 'session_epoch']);
        if (! $state || ! $state->active || (int) $request->session()->get('session_epoch', 0) !== (int) $state->session_epoch) {
            return false;
        }
        $sid = $request->session()->get('optinexus_sid');

        return ! (is_string($sid) && Cache::has(BackchannelLogoutService::revokedSidKey($sid)));
    }
}
