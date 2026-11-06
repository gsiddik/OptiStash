<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Mode: 'standalone' vs 'saas'
    |--------------------------------------------------------------------------
    */
    'deployment_mode' => env('OPTISTASH_DEPLOYMENT_MODE', 'standalone'),

    /*
    | Login identity mode read by AuthController and TenantContext: 'local' (users, roles and permissions in
    | this database) or 'optinexus' (sign-in through OptiNexus OIDC, permissions from OptiNexus). Any other
    | value makes login and tenant routes answer 503 instead of failing open.
    | In standalone deployment only the tenant with this slug may sign in.
    */
    'identity_mode' => env('OPTISTASH_IDENTITY_MODE', 'local'),
    'standalone_tenant' => env('OPTISTASH_STANDALONE_TENANT', 'demo'),

    /*
    |--------------------------------------------------------------------------
    | Identity & Access Management Driver
    | Options: 'local' (Local DB users/roles) | 'optinexus' (SSO & Central CGO)
    |--------------------------------------------------------------------------
    */
    'identity' => [
        'driver' => env('OPTISTASH_IDENTITY_MODE', 'local'),
    ],

    /*
    | OptiNexus adapter, used when identity_mode is 'optinexus' (docs/architecture/OPTINEXUS_ADAPTER.md).
    | Secrets come from the environment only.
    */
    'optinexus' => [
        'base_url' => rtrim((string) env('OPTINEXUS_BASE_URL', ''), '/'),
        'application_code' => env('OPTINEXUS_APPLICATION_CODE', 'optistash'),
        // Seconds a pulled permission answer is reused; never more than 300 or than the ttl OptiNexus sends.
        'permission_ttl_seconds' => min(300, max(0, (int) env('OPTINEXUS_PERMISSION_TTL', 300))),
        'sso' => [
            'client_id' => env('OPTINEXUS_SSO_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SSO_CLIENT_SECRET'),
            // Defaults to {APP_URL}/api/v1/auth/sso/callback; must be registered exactly on the OIDC client.
            'redirect_uri' => env('OPTINEXUS_SSO_REDIRECT_URI'),
            // Where the SPA runs; the callback lands the browser there and OptiNexus returns there after logout.
            'frontend_url' => rtrim((string) env('OPTISTASH_FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
            'state_ttl_seconds' => 600,
        ],
        'service' => [
            'client_id' => env('OPTINEXUS_SERVICE_CLIENT_ID'),
            'client_secret' => env('OPTINEXUS_SERVICE_CLIENT_SECRET'),
            'timeout_seconds' => (int) env('OPTINEXUS_TIMEOUT', 5),
        ],
        // Opti Integration Feed pairing (docs/integration/OPTI_INTEGRATION_FEED_V1.md): 'auto' = required while identity_mode is
        // 'optinexus' (SaaS), 'required' = always, 'off' = never (standalone installations only).
        'feed' => [
            'pairing' => env('OPTINEXUS_FEED_PAIRING', 'auto'),
            'consumer_application' => env('OPTINEXUS_FEED_CONSUMER_APPLICATION', 'optiaccounting'),
            'live_subscription_statuses' => ['ACTIVE', 'TRIAL', 'GRACE_PERIOD', 'PAST_DUE'],
            'cache_seconds' => 300,
        ],
        // Signed webhooks from the OptiNexus event broker (docs/architecture/OPTINEXUS_ADAPTER.md).
        'events' => [
            'webhook_secret' => env('OPTINEXUS_EVENT_WEBHOOK_SECRET'),
            'tolerance_seconds' => 300,
        ],
    ],

    // Calls per minute and signed-in user for the tenant API. A console page makes several calls and people move quickly between pages.
    'api_rate_limit' => max(30, (int) env('OPTISTASH_API_RATE_LIMIT', 120)),

    // Signing secret for events delivered to a tenant's third-party transport API (docs/architecture/INTEGRATION_ORCHESTRATOR.md).
    'outbox' => ['secret' => env('OPTISTASH_OUTBOX_SECRET')],
];
