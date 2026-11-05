<?php

namespace App\Domain\Iot;

use App\Domain\Inventory\InventoryService;
use App\Domain\Quality\CapaService;
use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IoT devices (tags and sensors) and their telemetry. Gateways deliver readings over REST (or an MQTT bridge that posts to the same
 * endpoint) with a bearer token; each reading is mapped to a device by its external id or MQTT topic. A reading outside the device's
 * limits raises one alert per breach series; when the device has auto-lock on, the stock bound to it (a location or a stock unit) is
 * locked and a non-conformance is raised, so goods exposed to a temperature excursion are not picked until quality decides.
 */
final class IotService
{
    public const METRICS = ['TEMPERATURE', 'HUMIDITY', 'WEIGHT', 'RFID', 'OTHER'];

    public const MAX_BATCH = 500;

    public function __construct(private readonly TenantStore $store) {}

    // --- gateways ---------------------------------------------------------------------------------------------------

    /** @return array{id:string,name:string,protocol:string,token:string} the token is returned this once and only its hash is kept */
    public function createGateway(string $name, string $protocol): array
    {
        abort_unless(in_array($protocol, ['REST', 'MQTT_BRIDGE'], true), 422, 'Protokol tidak dikenal.');
        $token = 'ost_'.Str::random(48);
        $id = (string) Str::uuid();

        return DB::transaction(function () use ($name, $protocol, $token, $id) {
            DB::table('iot_gateways')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'name' => $name, 'protocol' => $protocol, 'token_hash' => hash('sha256', $token), 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('iot.gateway_created', $id, ['name' => $name, 'protocol' => $protocol]);

            return ['id' => $id, 'name' => $name, 'protocol' => $protocol, 'token' => $token];
        }, 3);
    }

    public function rotateToken(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $g = $this->store->find('iot_gateways', $id, true);
            $token = 'ost_'.Str::random(48);
            DB::table('iot_gateways')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'created_by' => $this->store->actorId, 'updated_at' => now()]);
            $this->store->event('iot.gateway_token_rotated', $id, ['name' => $g->name]);

            return ['id' => $id, 'name' => $g->name, 'protocol' => $g->protocol, 'token' => $token];
        }, 3);
    }

    public function setGatewayActive(string $id, bool $active): void
    {
        DB::transaction(function () use ($id, $active) {
            $g = $this->store->find('iot_gateways', $id, true);
            DB::table('iot_gateways')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->store->event($active ? 'iot.gateway_activated' : 'iot.gateway_deactivated', $id, ['name' => $g->name]);
        }, 3);
    }

    public function gateways(): array
    {
        return $this->store->query('iot_gateways')->orderBy('name')->get(['id', 'name', 'protocol', 'active', 'last_seen_at', 'created_at'])->all();
    }

    // --- devices ----------------------------------------------------------------------------------------------------

    public function saveDevice(?string $id, array $d): object
    {
        abort_unless(in_array($d['metric'], self::METRICS, true), 422, 'Metrik tidak dikenal.');
        abort_if(isset($d['min_value'], $d['max_value']) && $d['min_value'] >= $d['max_value'], 422, 'Batas bawah harus lebih kecil dari batas atas.');
        $binding = $d['binding_type'] ?? 'NONE';
        abort_unless(in_array($binding, ['NONE', 'LOCATION', 'STOCK_UNIT'], true), 422, 'Jenis pengikatan tidak dikenal.');
        abort_if($binding !== 'NONE' && empty($d['binding_id']), 422, 'Pilih lokasi atau unit stok yang diikat.');
        abort_if(! empty($d['auto_lock']) && $binding === 'NONE', 422, 'Kunci otomatis memerlukan pengikatan ke lokasi atau unit stok.');

        return DB::transaction(function () use ($id, $d, $binding) {
            if ($binding === 'LOCATION') {
                $this->store->find('storage_nodes', $d['binding_id']);
            } elseif ($binding === 'STOCK_UNIT') {
                $this->store->find('stock_units', $d['binding_id']);
            }
            if (! empty($d['gateway_id'])) {
                $this->store->find('iot_gateways', $d['gateway_id']);
            }
            $row = [
                'gateway_id' => $d['gateway_id'] ?? null, 'name' => $d['name'], 'mqtt_topic' => $d['mqtt_topic'] ?? null, 'metric' => $d['metric'], 'unit' => $d['unit'] ?? null,
                'min_value' => $d['min_value'] ?? null, 'max_value' => $d['max_value'] ?? null, 'binding_type' => $binding, 'binding_id' => $binding === 'NONE' ? null : $d['binding_id'],
                'auto_lock' => (bool) ($d['auto_lock'] ?? false), 'stale_after_minutes' => $d['stale_after_minutes'] ?? null, 'updated_at' => now(),
            ];
            if ($id === null) {
                $code = Str::upper(trim($d['code']));
                abort_if($this->store->query('iot_devices')->where(fn ($q) => $q->where('code', $code)->orWhere('external_id', $d['external_id']))->exists(), 422, 'Kode atau ID eksternal perangkat sudah dipakai.');
                abort_if(! empty($d['mqtt_topic']) && $this->store->query('iot_devices')->where('mqtt_topic', $d['mqtt_topic'])->exists(), 422, 'Topik MQTT sudah dipakai perangkat lain.');
                $id = (string) Str::uuid();
                DB::table('iot_devices')->insert($row + ['id' => $id, 'tenant_id' => $this->store->tenantId, 'code' => $code, 'external_id' => $d['external_id'], 'created_at' => now()]);
                $this->store->event('iot.device_created', $id, ['code' => $code]);
            } else {
                $before = $this->store->find('iot_devices', $id, true);
                abort_if(! empty($d['mqtt_topic']) && $this->store->query('iot_devices')->where('mqtt_topic', $d['mqtt_topic'])->where('id', '!=', $id)->exists(), 422, 'Topik MQTT sudah dipakai perangkat lain.');
                DB::table('iot_devices')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update($row);
                $this->store->event('iot.device_updated', $id, ['code' => $before->code, 'binding' => $binding]);
            }

            return $this->store->find('iot_devices', $id);
        }, 3);
    }

    public function setDeviceActive(string $id, bool $active): void
    {
        DB::transaction(function () use ($id, $active) {
            $dv = $this->store->find('iot_devices', $id, true);
            DB::table('iot_devices')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $this->store->event($active ? 'iot.device_activated' : 'iot.device_deactivated', $id, ['code' => $dv->code]);
        }, 3);
    }

    public function devices(): array
    {
        return $this->store->query('iot_devices')->orderBy('code')->get()->all();
    }

    public function readings(string $deviceId, int $hours): array
    {
        $this->store->find('iot_devices', $deviceId);

        return $this->store->query('telemetry_readings')->where('device_id', $deviceId)->where('recorded_at', '>=', now()->subHours(min(max($hours, 1), 168)))
            ->orderByDesc('recorded_at')->limit(500)->get(['value', 'recorded_at'])->all();
    }

    // --- ingestion --------------------------------------------------------------------------------------------------

    /** The gateway a bearer token belongs to, or null. */
    public static function gatewayByToken(string $token): ?object
    {
        if ($token === '') {
            return null;
        }
        $g = DB::table('iot_gateways')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
        if ($g && ! DB::table('tenants')->where('id', $g->tenant_id)->where('active', true)->exists()) {
            return null;
        }

        return $g;
    }

    /**
     * Stores a batch of readings of one gateway. Each reading names its device by `device` (external id) or `topic` (MQTT topic).
     *
     * @param  list<array{device?:?string,topic?:?string,value:mixed,at?:?string}>  $items
     * @return array{accepted:int,rejected:list<array{index:int,reason:string}>}
     */
    public static function ingest(object $gateway, array $items): array
    {
        abort_if(count($items) > self::MAX_BATCH, 413, 'Satu kiriman maksimal '.self::MAX_BATCH.' pembacaan.');
        $store = new TenantStore($gateway->tenant_id, $gateway->created_by);
        $service = new self($store);
        $devices = $store->query('iot_devices')->where('active', true)->where(fn ($q) => $q->whereNull('gateway_id')->orWhere('gateway_id', $gateway->id))->get();
        $byExternal = $devices->keyBy('external_id');
        $byTopic = $devices->whereNotNull('mqtt_topic')->keyBy('mqtt_topic');
        $accepted = 0;
        $rejected = [];
        $now = CarbonImmutable::now();
        foreach ($items as $i => $item) {
            $device = isset($item['device']) ? ($byExternal[$item['device']] ?? null) : (isset($item['topic']) ? ($byTopic[$item['topic']] ?? null) : null);
            $value = $item['value'] ?? null;
            if (! $device) {
                $rejected[] = ['index' => $i, 'reason' => 'perangkat tidak dikenal'];
            } elseif (! is_numeric($value) || abs((float) $value) >= 1e9) {
                $rejected[] = ['index' => $i, 'reason' => 'nilai tidak valid'];
            } else {
                try {
                    $at = isset($item['at']) ? CarbonImmutable::parse($item['at']) : $now;
                } catch (\Throwable) {
                    $rejected[] = ['index' => $i, 'reason' => 'waktu tidak valid'];

                    continue;
                }
                if ($at->greaterThan($now->addMinutes(5)) || $at->lessThan($now->subDays(7))) {
                    $rejected[] = ['index' => $i, 'reason' => 'waktu di luar rentang yang diterima'];

                    continue;
                }
                $service->record($device, round((float) $value, 3), $at);
                $accepted++;
            }
        }
        DB::table('iot_gateways')->where('id', $gateway->id)->update(['last_seen_at' => $now]);

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    private function record(object $device, float $value, CarbonImmutable $at): void
    {
        DB::transaction(function () use ($device, $value, $at) {
            DB::table('telemetry_readings')->insert(['tenant_id' => $this->store->tenantId, 'device_id' => $device->id, 'value' => $value, 'recorded_at' => $at, 'received_at' => now()]);
            // Only a reading newer than the last one moves the device's current value.
            if ($device->last_seen_at === null || $at->greaterThanOrEqualTo(CarbonImmutable::parse($device->last_seen_at))) {
                DB::table('iot_devices')->where('tenant_id', $this->store->tenantId)->where('id', $device->id)->update(['last_value' => $value, 'last_seen_at' => $at]);
                $this->closeStale($device->id);
            }
            if ($device->max_value !== null && $value > (float) $device->max_value) {
                $this->breach($device, 'HIGH', $value, (float) $device->max_value);
            } elseif ($device->min_value !== null && $value < (float) $device->min_value) {
                $this->breach($device, 'LOW', $value, (float) $device->min_value);
            }
        }, 3);
    }

    private function closeStale(string $deviceId): void
    {
        $stale = $this->store->query('telemetry_alerts')->where('device_id', $deviceId)->where('kind', 'STALE')->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->pluck('id');
        if ($stale->isNotEmpty()) {
            DB::table('telemetry_alerts')->where('tenant_id', $this->store->tenantId)->whereIn('id', $stale)->update(['status' => 'CLOSED', 'closed_at' => now(), 'note' => 'Perangkat melapor kembali.', 'updated_at' => now()]);
        }
    }

    private function breach(object $device, string $kind, float $value, float $limit): void
    {
        $open = $this->store->query('telemetry_alerts')->where('device_id', $device->id)->where('kind', $kind)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->lockForUpdate()->first();
        if ($open) {
            DB::table('telemetry_alerts')->where('tenant_id', $this->store->tenantId)->where('id', $open->id)->update(['value' => $value, 'last_seen_at' => now(), 'occurrences' => $open->occurrences + 1, 'updated_at' => now()]);

            return;
        }
        $id = (string) Str::uuid();
        [$locked, $ncId] = $device->auto_lock ? $this->lockBound($device, $kind, $value, $limit) : [0, null];
        DB::table('telemetry_alerts')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'device_id' => $device->id, 'kind' => $kind, 'value' => $value, 'limit_value' => $limit, 'raised_at' => now(), 'last_seen_at' => now(),
            'locked_units' => $locked, 'nonconformance_id' => $ncId, 'created_at' => now(), 'updated_at' => now()]);
        $this->store->event('iot.alert_raised', $id, ['device' => $device->code, 'kind' => $kind, 'value' => $value, 'limit' => $limit, 'locked_units' => $locked]);
    }

    /** @return array{0:int,1:?string} units locked and the non-conformance raised */
    private function lockBound(object $device, string $kind, float $value, float $limit): array
    {
        $reason = "Alarm {$device->code}: {$value} {$device->unit} ".($kind === 'HIGH' ? 'di atas' : 'di bawah')." batas {$limit}";
        $inventory = new InventoryService($this->store);
        $locked = 0;
        if ($device->binding_type === 'LOCATION') {
            $locked = $inventory->lockLocation($device->binding_id, $reason);
        } elseif ($device->binding_type === 'STOCK_UNIT') {
            $unit = $this->store->query('stock_units')->where('id', $device->binding_id)->first();
            if ($unit && $unit->status === 'AVAILABLE' && ! $unit->locked) {
                $inventory->setLocked($unit->id, true, $reason);
                $locked = 1;
            }
        }
        $nc = null;
        if ($locked > 0) {
            $nc = (new CapaService($this->store))->raiseNonconformance('IOT', 'TEMPERATURE', "Alarm {$device->code}", "{$reason}. {$locked} unit stok dikunci otomatis; putuskan lepas atau karantina.")->id;
        }

        return [$locked, $nc];
    }

    /** Raises a STALE alert for every active device whose last reading is older than its allowed silence. @return int alerts raised */
    public static function checkStale(): int
    {
        $raised = 0;
        foreach (DB::table('iot_devices')->where('active', true)->whereNotNull('stale_after_minutes')->get() as $d) {
            $silent = $d->last_seen_at === null || CarbonImmutable::parse($d->last_seen_at)->addMinutes($d->stale_after_minutes)->isPast();
            $gw = $d->gateway_id ? DB::table('iot_gateways')->where('id', $d->gateway_id)->first() : DB::table('iot_gateways')->where('tenant_id', $d->tenant_id)->where('active', true)->orderBy('created_at')->first();
            if (! $silent || ! $gw || DB::table('telemetry_alerts')->where('device_id', $d->id)->where('kind', 'STALE')->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->exists()) {
                continue;
            }
            $store = new TenantStore($d->tenant_id, $gw->created_by);
            $id = (string) Str::uuid();
            DB::table('telemetry_alerts')->insert(['id' => $id, 'tenant_id' => $d->tenant_id, 'device_id' => $d->id, 'kind' => 'STALE', 'raised_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $store->event('iot.alert_raised', $id, ['device' => $d->code, 'kind' => 'STALE']);
            $raised++;
        }

        return $raised;
    }

    // --- alerts -----------------------------------------------------------------------------------------------------

    public function alerts(?string $status): array
    {
        return DB::table('telemetry_alerts as a')->where('a.tenant_id', $this->store->tenantId)->when($status, fn ($q) => $q->where('a.status', $status))
            ->join('iot_devices as d', fn ($j) => $j->on('d.id', '=', 'a.device_id')->on('d.tenant_id', '=', 'a.tenant_id'))->orderByDesc('a.raised_at')->limit(200)
            ->get(['a.id', 'a.kind', 'a.value', 'a.limit_value', 'a.status', 'a.raised_at', 'a.last_seen_at', 'a.occurrences', 'a.locked_units', 'a.nonconformance_id', 'a.note', 'd.code as device_code', 'd.name as device_name', 'd.unit'])->all();
    }

    public function acknowledge(string $id, string $note): object
    {
        abort_if(mb_strlen(trim($note)) < 5, 422, 'Catatan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $note) {
            $a = $this->store->find('telemetry_alerts', $id, true);
            abort_unless($a->status === 'OPEN', 422, 'Hanya alarm OPEN yang dapat diakui.');
            DB::table('telemetry_alerts')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'ACKNOWLEDGED', 'acknowledged_by' => $this->store->actorId, 'note' => trim($note), 'updated_at' => now()]);
            $this->store->event('iot.alert_acknowledged', $id, []);

            return $this->store->find('telemetry_alerts', $id);
        }, 3);
    }

    public function close(string $id, string $note): object
    {
        abort_if(mb_strlen(trim($note)) < 5, 422, 'Catatan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $note) {
            $a = $this->store->find('telemetry_alerts', $id, true);
            abort_unless(in_array($a->status, ['OPEN', 'ACKNOWLEDGED'], true), 422, 'Alarm sudah ditutup.');
            DB::table('telemetry_alerts')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CLOSED', 'closed_at' => now(), 'note' => trim($note), 'acknowledged_by' => $a->acknowledged_by ?? $this->store->actorId, 'updated_at' => now()]);
            $this->store->event('iot.alert_closed', $id, []);

            return $this->store->find('telemetry_alerts', $id);
        }, 3);
    }
}
