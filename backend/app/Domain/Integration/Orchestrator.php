<?php

namespace App\Domain\Integration;

use App\Domain\Shared\TenantStore;
use App\Domain\Warehouse\TransportSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The integration orchestrator. Business events leave OptiStash through the transactional outbox; this class decides where they go and
 * delivers them. Standalone mode keeps everything inside OptiStash (events stay queued, nothing is sent). Otherwise the tenant's
 * transport mode picks the route: THIRD_PARTY_API posts each shipment event, signed (HMAC-SHA256, same scheme as OptiNexus webhooks), to
 * the configured https address with retries and back-off; OPTIDELI events wait for the OptiDeli receiving contract (see CHECKPOINT.md);
 * MANUAL has nothing to deliver. "Force sync" retries failed events at once and delivers what is due.
 */
final class Orchestrator
{
    public const MAX_ATTEMPTS = 8;

    public const ROUTED_PREFIXES = ['shipment.', 'outbound.'];

    public const KEY = 'orchestrator';

    public static function settings(string $tenantId): array
    {
        $row = DB::table('tenant_settings')->where('tenant_id', $tenantId)->where('key', self::KEY)->value('value');

        // A standalone installation starts standalone; a SaaS tenant starts connected.
        return ($row ? json_decode($row, true) : null) ?? ['standalone' => config('optistash.deployment_mode') === 'standalone'];
    }

    public static function setStandalone(TenantStore $store, bool $standalone): array
    {
        return DB::transaction(function () use ($store, $standalone) {
            DB::table('tenants')->where('id', $store->tenantId)->lockForUpdate()->firstOrFail();
            $before = self::settings($store->tenantId);
            $value = ['standalone' => $standalone];
            DB::table('tenant_settings')->upsert(['tenant_id' => $store->tenantId, 'key' => self::KEY, 'value' => json_encode($value), 'updated_at' => now()], ['tenant_id', 'key'], ['value', 'updated_at']);
            if ($before !== $value) {
                $store->event('integration.standalone_changed', $store->tenantId, ['from' => $before['standalone'], 'to' => $standalone]);
            }

            return $value;
        }, 3);
    }

    private static function routed(string $type): bool
    {
        foreach (self::ROUTED_PREFIXES as $p) {
            if (str_starts_with($type, $p)) {
                return true;
            }
        }

        return false;
    }

    public static function status(string $tenantId): array
    {
        $transport = TransportSettings::get($tenantId);
        $base = DB::table('outbox_events')->where('tenant_id', $tenantId)->where(fn ($q) => $q->where('type', 'like', 'shipment.%')->orWhere('type', 'like', 'outbound.%'));
        $count = fn ($q) => (int) $q->count();

        return [
            'deployment_mode' => config('optistash.deployment_mode'), 'identity_mode' => config('optistash.identity_mode'),
            'standalone' => self::settings($tenantId)['standalone'], 'transport' => $transport,
            'outbox' => [
                'pending' => $count((clone $base)->whereNull('published_at')->where('attempts', '<', self::MAX_ATTEMPTS)),
                'failed' => $count((clone $base)->whereNull('published_at')->where('attempts', '>=', self::MAX_ATTEMPTS)),
                'delivered' => $count((clone $base)->whereNotNull('published_at')),
                'last_error' => (clone $base)->whereNotNull('last_error')->orderByDesc('created_at')->value('last_error'),
            ],
            'iot' => ['gateways' => (int) DB::table('iot_gateways')->where('tenant_id', $tenantId)->where('active', true)->count(), 'devices' => (int) DB::table('iot_devices')->where('tenant_id', $tenantId)->where('active', true)->count(),
                'open_alerts' => (int) DB::table('telemetry_alerts')->where('tenant_id', $tenantId)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->count()],
        ];
    }

    /**
     * Delivers what is due for one tenant (or every tenant when null). $force also retries events that ran out of attempts.
     *
     * @return array{delivered:int,failed:int,skipped:string|null}
     */
    public static function deliver(?string $tenantId, bool $force = false): array
    {
        $result = ['delivered' => 0, 'failed' => 0, 'skipped' => null];
        $tenants = $tenantId ? [$tenantId] : DB::table('outbox_events')->whereNull('published_at')->distinct()->pluck('tenant_id')->all();
        foreach ($tenants as $t) {
            if (self::settings($t)['standalone']) {
                $result['skipped'] = 'standalone';

                continue;
            }
            $transport = TransportSettings::get($t);
            if ($transport['mode'] !== 'THIRD_PARTY_API') {
                $result['skipped'] = $transport['mode'] === 'OPTIDELI' ? 'optideli-contract-pending' : 'manual';

                continue;
            }
            $secret = (string) config('optistash.outbox.secret');
            if ($secret === '') {
                $result['skipped'] = 'no-signing-secret';

                continue;
            }
            $events = DB::table('outbox_events')->where('tenant_id', $t)->whereNull('published_at')->where(fn ($q) => $q->where('type', 'like', 'shipment.%')->orWhere('type', 'like', 'outbound.%'))
                ->when(! $force, fn ($q) => $q->where('attempts', '<', self::MAX_ATTEMPTS)->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
                ->orderBy('created_at')->limit(100)->get();
            foreach ($events as $e) {
                if (! self::routed($e->type)) {
                    continue;
                }
                $ok = self::send($transport['api_url'], $secret, $e);
                $ok === true ? $result['delivered']++ : $result['failed']++;
                if ($ok === true) {
                    DB::table('outbox_events')->where('id', $e->id)->update(['published_at' => now(), 'last_error' => null]);
                } else {
                    $attempts = $e->attempts + 1;
                    DB::table('outbox_events')->where('id', $e->id)->update(['attempts' => $attempts, 'last_error' => Str::limit((string) $ok, 290, ''), 'next_attempt_at' => now()->addMinutes(min(2 ** $attempts, 60))]);
                }
            }
        }

        return $result;
    }

    /** @return true|string true on a 2xx answer, otherwise the reason */
    private static function send(string $url, string $secret, object $event): bool|string
    {
        $body = json_encode(['id' => $event->id, 'type' => $event->type, 'tenant' => $event->tenant_id, 'payload' => json_decode($event->payload, true)], JSON_THROW_ON_ERROR);
        $ts = (string) time();
        try {
            $res = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json', 'Opti-Webhook-Id' => $event->id, 'Opti-Webhook-Timestamp' => $ts,
                'Opti-Webhook-Signature' => 'v1='.hash_hmac('sha256', $event->id.'.'.$ts.'.'.$body, $secret),
            ])->withBody($body, 'application/json')->post($url);
        } catch (\Throwable $e) {
            return 'Koneksi gagal: '.$e->getMessage();
        }

        return $res->successful() ? true : 'HTTP '.$res->status();
    }
}
