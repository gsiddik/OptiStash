<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\SessionState;
use App\Domain\Integration\Optinexus\AccountLinker;
use App\Domain\Integration\Optinexus\BackchannelLogoutService;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Platform\PlatformAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Masuk dengan OptiNexus" (identity mode `optinexus`, docs/architecture/OPTINEXUS_ADAPTER.md).
 * GET /auth/sso/redirect -> OptiNexus -> GET /auth/sso/callback, which opens the session cookie and lands the browser on
 * the SPA. Nothing secret ever travels in a URL.
 */
class SsoController extends Controller
{
    public function __construct(private readonly OidcClient $oidc) {}

    /** Public; tells the login page which doors exist. No secrets. */
    public function status(): JsonResponse
    {
        return response()->json(['data' => [
            'identity_mode' => config('optistash.identity_mode'),
            'sso_enabled' => OptinexusSettings::ssoEnabled(),
            'password_login' => ! OptinexusSettings::active(),
        ]]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! OptinexusSettings::ssoEnabled()) {
            return $this->failure('sso_disabled');
        }
        $hint = $request->query('tenant_hint');
        // Another product's switcher may name the tenant; only a tenant id or code is forwarded, never anything else.
        $hint = is_string($hint) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $hint) ? $hint : null;

        try {
            return redirect()->away($this->oidc->authorizationUrl($hint));
        } catch (IdentityProviderUnavailable) {
            return $this->failure('sso_unavailable');
        }
    }

    public function callback(Request $request, AccountLinker $linker): RedirectResponse
    {
        if (! OptinexusSettings::ssoEnabled()) {
            return $this->failure('sso_disabled');
        }
        if ($request->query('error')) {
            return $this->failure($request->query('error') === 'access_denied' ? 'access_denied' : 'sso_failed');
        }
        $code = (string) $request->query('code');
        $state = (string) $request->query('state');
        if ($code === '' || $state === '') {
            return $this->failure('sso_failed');
        }

        try {
            $claims = $this->oidc->completeLogin($code, $state, $request->query('iss') ? (string) $request->query('iss') : null);
            [$user, $tenant] = $linker->link($claims);
        } catch (SsoException $e) {
            if (isset($claims)) {
                // The claims are verified here, so a refusal is worth a record.
                PlatformAccess::audit(null, 'auth.sso_refused', (string) ($claims['tenant_id'] ?? ''), ['reason' => $e->getMessage(), 'optinexus_subject' => $claims['sub'] ?? null]);
            }

            return $this->failure($e->getMessage());
        } catch (IdentityProviderUnavailable) {
            return $this->failure('sso_unavailable');
        } catch (\Throwable $e) {
            report($e);

            return $this->failure('sso_failed');
        }

        SessionState::start($request, $user, $tenant->id, ['sid' => is_string($claims['sid'] ?? null) ? $claims['sid'] : null, 'id_token_hint' => $claims['_id_token_hint'] ?? null]);

        return redirect()->away(OptinexusSettings::frontendUrl().'/');
    }

    /** OIDC Back-Channel Logout 1.0: 200 when accepted (also for an unknown user), 400 for an invalid token. */
    public function backchannelLogout(Request $request, BackchannelLogoutService $logout): Response
    {
        $token = $request->input('logout_token');
        if (! OptinexusSettings::active() || ! is_string($token) || $token === '' || strlen($token) > 8192) {
            return response('', 400);
        }

        try {
            $logout->apply($this->oidc->verifyLogoutToken($token));
        } catch (SsoException) {
            return response('', 400);
        } catch (IdentityProviderUnavailable) {
            return response('', 503); // OptiNexus retries
        }

        return response('', 200)->header('Cache-Control', 'no-store');
    }

    private function failure(string $code): RedirectResponse
    {
        return redirect()->away(OptinexusSettings::frontendUrl().'/?'.http_build_query(['sso_error' => $code]));
    }
}
