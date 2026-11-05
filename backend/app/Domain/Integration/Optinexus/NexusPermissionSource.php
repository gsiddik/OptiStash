<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Access\PermissionCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Permissions in `optinexus` identity mode: OptiNexus decides (roles and assignments live there). One request,
 * POST /authorization/effective-permissions (schema 1.0), returns every permission the member holds in the tenant for the
 * application bound to our service account. Only exact codes of the local catalog are accepted, as `optistash.<code>`, and only
 * for modules in `entitled_capabilities` (core permissions need no module). The
 * answer is cached per tenant + membership for at most 300 seconds (never longer than OptiNexus' `ttl_seconds`), so a grant
 * revoked in OptiNexus stops working here within that time, at once on a back-channel logout. A failed call caches nothing
 * and raises IdentityProviderUnavailable: access fails closed, with no local fallback.
 */
class NexusPermissionSource
{
    public const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly NexusApi $api) {}

    /** @return list<string> */
    public function forMembership(object $membership, object $tenant): array
    {
        $key = self::cacheKey($tenant->id, $membership->id);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $subject = DB::table('users')->where('id', $membership->user_id)->value('optinexus_subject');
        if (! $subject || ! $tenant->optinexus_tenant_id) {
            return []; // not linked to OptiNexus: nothing can be granted
        }

        $pulled = $this->pull((string) $tenant->optinexus_tenant_id, (string) $subject);
        $this->prime($tenant->id, $membership->id, $pulled['permissions'], $pulled['ttl']);

        return $pulled['permissions'];
    }

    /** @param list<string> $permissions */
    public function prime(string $tenantId, int $membershipId, array $permissions, int $ttl): void
    {
        $ttl = min($ttl, (int) config('optistash.optinexus.permission_ttl_seconds'));
        if ($permissions !== [] && $ttl > 0) {
            Cache::put(self::cacheKey($tenantId, $membershipId), $permissions, $ttl);
        } else {
            self::forget($tenantId, $membershipId);
        }
    }

    public static function forget(string $tenantId, int $membershipId): void
    {
        Cache::forget(self::cacheKey($tenantId, $membershipId));
    }

    /**
     * Asks OptiNexus what one user holds in one tenant. An answer about another user, tenant, application or schema is not
     * an answer; a key outside the local catalog, a wildcard or a malformed row is dropped.
     *
     * @return array{permissions:list<string>,ttl:int}
     *
     * @throws IdentityProviderUnavailable
     */
    public function pull(string $nexusTenantId, string $subject): array
    {
        $application = OptinexusSettings::applicationCode();
        $data = $this->api->data($this->api->send('POST', '/authorization/effective-permissions', [
            'user_id' => $subject,
            'tenant_id' => $nexusTenantId,
            'application_code' => $application,
        ], repeatable: true));

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($data['user_id'] ?? null) !== $subject
            || ($data['tenant_id'] ?? null) !== $nexusTenantId
            || ($data['application_code'] ?? null) !== $application
            || ! is_array($data['permissions'] ?? null)) {
            throw new IdentityProviderUnavailable('Unusable answer from effective-permissions (schema or subject mismatch).');
        }

        $ttl = is_int($data['ttl_seconds'] ?? null) ? max(0, $data['ttl_seconds']) : 0;
        // An explicit refusal always wins, even when the answer also carries grants.
        if (($data['allowed'] ?? false) !== true) {
            return ['permissions' => [], 'ttl' => $ttl];
        }

        $catalog = array_flip(DB::table('permissions')->pluck('code')->all());
        $prefix = "{$application}.";
        // Module entitlement is enforced here: a permission of a module the tenant has not bought is dropped.
        $entitled = [];
        foreach ((array) ($data['entitled_capabilities'] ?? []) as $capability) {
            if (is_array($capability) && is_string($capability['code'] ?? null)) {
                $entitled[$capability['code']] = true;
            }
        }
        $permissions = [];
        foreach ($data['permissions'] as $row) {
            $key = is_array($row) ? ($row['permission'] ?? null) : null;
            if (! is_string($key) || ! str_starts_with($key, $prefix) || ! isset($catalog[$code = substr($key, strlen($prefix))])) {
                continue;
            }
            $module = PermissionCatalog::moduleOf($code);
            if ($module === null || isset($entitled["{$prefix}{$module}"])) {
                $permissions[$code] = true;
            }
        }
        $permissions = array_keys($permissions);
        sort($permissions);

        return ['permissions' => $permissions, 'ttl' => $ttl];
    }

    private static function cacheKey(string $tenantId, int $membershipId): string
    {
        return "optinexus.permissions.{$tenantId}.{$membershipId}";
    }
}
