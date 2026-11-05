<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Access\PermissionCatalog;

/**
 * OptiStash's catalog for OptiNexus, manifest v2 (`php artisan ecosystem:import-manifest optistash <file> --create-application` in
 * OptiNexus). Built from PermissionCatalog, so the registration cannot drift from the code. `revision` is a hash of the catalog
 * only (not of deployment URLs), so it changes exactly when a capability, permission or delegable flag changes.
 */
class OptinexusManifest
{
    public const VERSION = '2.0';

    /** @return array<string,mixed> */
    public function build(): array
    {
        $application = OptinexusSettings::applicationCode();
        $frontend = OptinexusSettings::frontendUrl();

        $capabilities = [];
        $order = 10;
        foreach (PermissionCatalog::MODULES as $module => $definition) {
            $capabilities[] = ['type' => 'MODULE', 'code' => "{$application}.{$module}", 'name' => $definition['name'], 'description' => $definition['description'], 'sort_order' => $order, 'parent_code' => null];
            $order += 10;
        }

        $permissions = [];
        foreach (PermissionCatalog::DEFINITIONS as $group => $definitions) {
            foreach ($definitions as $code => $description) {
                $module = PermissionCatalog::moduleOf($code);
                $permissions[] = [
                    'permission_key' => "{$application}.{$code}",
                    'name' => "{$group}: {$code}",
                    'description' => $description,
                    'capability_code' => $module === null ? null : "{$application}.{$module}",
                    'delegable' => ! in_array($code, PermissionCatalog::NOT_DELEGABLE, true),
                ];
            }
        }
        usort($permissions, fn ($a, $b) => strcmp($a['permission_key'], $b['permission_key']));

        $catalog = ['capabilities' => $capabilities, 'permissions' => $permissions, 'scopes' => explode(' ', NexusApi::SCOPES)];

        return [
            'manifest_version' => self::VERSION,
            'revision' => 'r'.substr(hash('sha256', (string) json_encode($catalog, JSON_UNESCAPED_SLASHES)), 0, 12),
            'application_code' => $application,
            'application' => [
                'application_code' => $application,
                'name' => 'OptiStash',
                'description' => 'Transport management: orders, dispatch, trip execution, yard and freight audit.',
                'frontend_url' => $frontend,
            ],
            'capabilities' => $capabilities,
            'permissions' => $permissions,
            // Registration data for the operator (not imported by the manifest command; see docs/architecture/OPTINEXUS_ADAPTER.md §6).
            'service_account' => ['scopes' => $catalog['scopes']],
            'oidc_client' => [
                'redirect_uris' => [OptinexusSettings::redirectUri()],
                'post_logout_redirect_uris' => [$frontend.'/'],
                'backchannel_logout_uri' => url('/api/v1/integration/optinexus/backchannel-logout'),
                'launch_url' => $frontend,
                'scopes' => ['openid', 'profile', 'email'],
            ],
        ];
    }
}
