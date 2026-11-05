<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * OptiStash's machine-to-machine client of the OptiNexus API (client credentials). The service account is bound to the
 * `optistash` application in OptiNexus; the tenant is named in the request body, always from a verified source, never
 * from a browser. Only "could not talk to OptiNexus at all" raises IdentityProviderUnavailable; each caller decides
 * what a 4xx means.
 */
class NexusApi
{
    public const SCOPES = 'authorization.check';

    /** Reads the commercial state of one tenant (tenant status, subscriptions, entitled applications). */
    public const SCOPE_COMMERCIAL = 'commercial.read';

    /** Reports chargeable usage (the monthly seat count) to OptiNexus. Granted to the service account explicitly. */
    public const SCOPE_USAGE = 'usage.write';

    /** Asks OptiNexus for a short-lived OptiRadar embed session (K5). Granted to the service account explicitly. */
    public const SCOPE_EMBED = 'embed.create';

    /** Reporting events to OptiNexus (master data sync). Granted to the service account explicitly. */
    public const SCOPE_EVENT = 'event.write';

    private const TOKEN_KEY = 'optinexus.service.token';

    /**
     * One call with bounded recovery: a 401 renews the cached token once; a read (`$repeatable`) is repeated once after a
     * connection error or 502/503/504. Every other status is returned as it is.
     *
     * @param  array<string,mixed>  $data
     */
    public function send(string $method, string $path, array $data = [], bool $repeatable = false, string $scope = self::SCOPES): Response
    {
        $renewed = false;
        $repeated = false;
        $correlationId = (string) Str::uuid();

        while (true) {
            try {
                $response = Http::withToken($this->token($scope))->acceptJson()->withoutRedirecting()
                    ->withHeaders(['X-Correlation-Id' => $correlationId])
                    ->timeout(OptinexusSettings::timeout())
                    ->send(strtoupper($method), OptinexusSettings::baseUrl().'/api/v1'.$path, ['json' => $data]);
            } catch (ConnectionException $e) {
                if (! $repeatable || $repeated) {
                    throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200), transient: true);
                }
                $repeated = true;
                Sleep::for(1)->seconds();

                continue;
            }

            if ($response->status() === 401 && ! $renewed) {
                Cache::forget($this->tokenKey($scope));
                $renewed = true;

                continue;
            }

            if ($repeatable && ! $repeated && in_array($response->status(), [502, 503, 504], true)) {
                $repeated = true;
                Sleep::for(1)->seconds();

                continue;
            }

            return $response;
        }
    }

    /**
     * The `data` member of a 2xx answer. Anything else means OptiNexus did not give a usable answer.
     *
     * @return array<string,mixed>
     */
    public function data(Response $response): array
    {
        if (! $response->successful()) {
            throw new IdentityProviderUnavailable("HTTP {$response->status()}", transient: $response->serverError());
        }

        return (array) ($response->json('data') ?? []);
    }

    private function tokenKey(string $scope): string
    {
        return $scope === self::SCOPES ? self::TOKEN_KEY : self::TOKEN_KEY.':'.$scope;
    }

    private function token(string $scope): string
    {
        if ($missing = OptinexusSettings::missingForService()) {
            throw new IdentityProviderUnavailable('Service account is not configured: '.implode(', ', $missing));
        }

        $key = $this->tokenKey($scope);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // One worker renews the token; the others wait for it instead of each asking OptiNexus at the same moment.
        try {
            return Cache::lock($key.':renewal', 15)->block(10, function () use ($key, $scope) {
                $cached = Cache::get($key);

                return is_string($cached) && $cached !== '' ? $cached : $this->requestToken($scope);
            });
        } catch (LockTimeoutException) {
            throw new IdentityProviderUnavailable('Service account token renewal timed out.', transient: true);
        }
    }

    private function requestToken(string $scope): string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(OptinexusSettings::timeout())
                ->post(OptinexusSettings::baseUrl().'/api/v1/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('optistash.optinexus.service.client_id'),
                    'client_secret' => config('optistash.optinexus.service.client_secret'),
                    'scope' => $scope,
                ]);
        } catch (ConnectionException $e) {
            throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200), transient: true);
        }

        $token = $response->json('access_token');
        if (! $response->ok() || ! is_string($token) || $token === '') {
            throw new IdentityProviderUnavailable("Service account authentication failed (HTTP {$response->status()}).", transient: $response->serverError());
        }

        Cache::put($this->tokenKey($scope), $token, max(60, (int) ($response->json('expires_in') ?? 3600) - 120));

        return $token;
    }
}
