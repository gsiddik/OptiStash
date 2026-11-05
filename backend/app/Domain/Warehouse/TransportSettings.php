<?php

namespace App\Domain\Warehouse;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;

/** How goods leave and arrive: through OptiDeli (the ecosystem TMS), a third-party transport API, or recorded by hand. One setting per tenant. */
final class TransportSettings
{
    public const MODES = ['OPTIDELI', 'THIRD_PARTY_API', 'MANUAL'];

    public const KEY = 'transport';

    public static function get(string $tenantId): array
    {
        $row = DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', self::KEY)->value('value');

        return ($row ? json_decode($row, true) : null) ?? ['mode' => 'MANUAL', 'api_url' => null];
    }

    public static function put(TenantStore $store, string $mode, ?string $apiUrl): array
    {
        abort_unless(in_array($mode, self::MODES, true), 422, 'Mode transportasi tidak dikenal.');
        abort_if($mode === 'THIRD_PARTY_API' && ($apiUrl === null || ! preg_match('#^https://\S+$#', $apiUrl)), 422, 'API pihak ketiga memerlukan alamat https.');
        $value = ['mode' => $mode, 'api_url' => $mode === 'THIRD_PARTY_API' ? $apiUrl : null];

        return DB::transaction(function () use ($store, $value) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $before = self::get($store->tenantId);
            DB::table('tenant_settings')->upsert(['tenant_id' => $store->tenantId, 'key' => self::KEY, 'value' => json_encode($value), 'updated_at' => now()], ['tenant_id', 'key'], ['value', 'updated_at']);
            if ($before !== $value) {
                $store->event('integration.transport_changed', $store->tenantId, ['from' => $before['mode'], 'to' => $value['mode']]);
            }

            return $value;
        }, 3);
    }
}
