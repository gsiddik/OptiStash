<?php

namespace App\Domain\Integration\Optinexus;

/** Read-only view of the OptiNexus settings; keeps "is it on and complete" in one place. */
final class OptinexusSettings
{
    public static function active(): bool
    {
        return config('optistash.identity_mode') === 'optinexus';
    }

    public static function baseUrl(): string
    {
        return (string) config('optistash.optinexus.base_url');
    }

    public static function applicationCode(): string
    {
        return (string) config('optistash.optinexus.application_code');
    }

    public static function clientId(): string
    {
        return (string) config('optistash.optinexus.sso.client_id');
    }

    public static function frontendUrl(): string
    {
        return (string) config('optistash.optinexus.sso.frontend_url');
    }

    public static function redirectUri(): string
    {
        return (string) (config('optistash.optinexus.sso.redirect_uri') ?: url('/api/v1/auth/sso/callback'));
    }

    public static function timeout(): int
    {
        return max(1, (int) config('optistash.optinexus.service.timeout_seconds'));
    }

    /** @return list<string> settings still missing for the OIDC sign-in */
    public static function missingForSso(): array
    {
        return self::missing([
            'OPTINEXUS_BASE_URL' => self::baseUrl(),
            'OPTINEXUS_SSO_CLIENT_ID' => config('optistash.optinexus.sso.client_id'),
            'OPTINEXUS_SSO_CLIENT_SECRET' => config('optistash.optinexus.sso.client_secret'),
        ]);
    }

    /** @return list<string> settings still missing for the service account (permissions) */
    public static function missingForService(): array
    {
        return self::missing([
            'OPTINEXUS_BASE_URL' => self::baseUrl(),
            'OPTINEXUS_SERVICE_CLIENT_ID' => config('optistash.optinexus.service.client_id'),
            'OPTINEXUS_SERVICE_CLIENT_SECRET' => config('optistash.optinexus.service.client_secret'),
        ]);
    }

    public static function ssoEnabled(): bool
    {
        return self::active() && self::missingForSso() === [] && self::missingForService() === [];
    }

    /** @param array<string,mixed> $required */
    private static function missing(array $required): array
    {
        return array_keys(array_filter($required, fn ($value) => $value === null || $value === ''));
    }
}
