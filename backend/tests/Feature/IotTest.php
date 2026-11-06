<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

class IotTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private string $tenant;

    private string $wh;

    private string $bin;

    private string $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        $this->wh = $this->postJson('/api/v1/master/warehouses', ['code' => 'WH1', 'name' => 'G', 'granularity' => 'ZONE'])->json('data.id');
        $this->bin = $this->postJson("/api/v1/warehouses/{$this->wh}/locations", ['code' => 'Z1', 'name' => 'Cold room'])->json('data.id');
        $uom = $this->postJson('/api/v1/master/uoms', ['code' => 'PCS', 'name' => 'P'])->json('data.id');
        $p = (string) Str::uuid();
        DB::table('products')->insert(['id' => $p, 'tenant_id' => $this->tenant, 'code' => 'ICE', 'name' => 'Es', 'base_uom_id' => $uom, 'rotation_policy' => 'FIFO', 'track_lot' => false, 'track_expiry' => false,
            'temperature_class' => 'FROZEN', 'hazmat' => false, 'criticality' => 'D', 'created_at' => now(), 'updated_at' => now()]);
        $this->unit = (string) Str::uuid();
        DB::table('stock_units')->insert(['id' => $this->unit, 'tenant_id' => $this->tenant, 'lpn' => 'LPN-1', 'warehouse_id' => $this->wh, 'product_id' => $p, 'quantity' => 40, 'location_id' => $this->bin,
            'status' => 'AVAILABLE', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{id:string,token:string} */
    private function gateway(string $protocol = 'REST'): array
    {
        return $this->postJson('/api/v1/iot/gateways', ['name' => 'Gateway gudang', 'protocol' => $protocol])->assertCreated()->json('data');
    }

    private function device(array $extra = []): array
    {
        return $this->postJson('/api/v1/iot/devices', $extra + ['code' => 'th-1', 'name' => 'Sensor cold room', 'external_id' => 'sensor-1', 'metric' => 'TEMPERATURE', 'unit' => 'C', 'min_value' => -25, 'max_value' => -15,
            'binding_type' => 'LOCATION', 'binding_id' => $this->bin, 'auto_lock' => true])->assertCreated()->json('data');
    }

    private function ingest(string $token, array $readings, int $status = 202)
    {
        auth()->forgetGuards();
        $this->flushSession();

        return $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/integration/iot/readings', ['readings' => $readings])->assertStatus($status);
    }

    public function test_the_gateway_token_is_shown_once_and_authenticates_ingestion(): void
    {
        $g = $this->gateway();
        $this->assertStringStartsWith('ost_', $g['token']);
        $this->assertNotContains($g['token'], array_map('strval', DB::table('iot_gateways')->first() ? (array) DB::table('iot_gateways')->first() : []));
        $this->assertArrayNotHasKey('token', $this->getJson('/api/v1/iot/gateways')->json('data.0'));
        $this->device();
        $this->ingest('wrong-token', [['device' => 'sensor-1', 'value' => -20]], 401);
        $res = $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -20.5], ['device' => 'nope', 'value' => 1], ['device' => 'sensor-1', 'value' => 'abc'], ['device' => 'sensor-1', 'value' => -20, 'at' => now()->addDay()->toIso8601String()]])->json('data');
        $this->assertSame(1, $res['accepted']);
        $this->assertSame(['perangkat tidak dikenal', 'nilai tidak valid', 'waktu di luar rentang yang diterima'], array_column($res['rejected'], 'reason'));
        $this->assertSame(-20.5, (float) DB::table('iot_devices')->value('last_value'));
        $this->assertNotNull(DB::table('iot_gateways')->value('last_seen_at'));

        // Rotating invalidates the old token; deactivating blocks the gateway.
        $this->actingAs(User::first())->withSession(['tenant_id' => $this->tenant]);
        $new = $this->postJson("/api/v1/iot/gateways/{$g['id']}/rotate-token")->assertOk()->json('data.token');
        $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -20]], 401);
        $this->ingest($new, [['device' => 'sensor-1', 'value' => -20]]);
        $this->actingAs(User::first())->withSession(['tenant_id' => $this->tenant]);
        $this->postJson("/api/v1/iot/gateways/{$g['id']}/status", ['active' => false])->assertNoContent();
        $this->ingest($new, [['device' => 'sensor-1', 'value' => -20]], 401);
        $this->assertSame(2, DB::table('telemetry_readings')->count());
    }

    public function test_a_temperature_excursion_raises_one_alert_locks_the_stock_and_raises_a_nonconformance(): void
    {
        $g = $this->gateway();
        $this->device();
        $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -18]]); // in range
        $this->assertSame(0, DB::table('telemetry_alerts')->count());
        $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -10], ['device' => 'sensor-1', 'value' => -9]]);
        $alert = DB::table('telemetry_alerts')->first();
        $this->assertSame(1, DB::table('telemetry_alerts')->count()); // one alert per series
        $this->assertSame(['HIGH', 2, 1], [$alert->kind, $alert->occurrences, $alert->locked_units]);
        $this->assertSame(1, (int) DB::table('stock_units')->where('id', $this->unit)->where('locked', true)->count());
        $nc = DB::table('nonconformances')->where('id', $alert->nonconformance_id)->first();
        $this->assertSame(['IOT', 'TEMPERATURE'], [$nc->source, $nc->category]);
        $this->assertStringContainsString('TH-1', $nc->description);

        $this->actingAs(User::first())->withSession(['tenant_id' => $this->tenant]);
        $list = $this->getJson('/api/v1/iot/alerts?status=OPEN')->assertOk()->json('data');
        $this->assertSame(['TH-1', 'HIGH'], [$list[0]['device_code'], $list[0]['kind']]);
        $this->postJson("/api/v1/iot/alerts/{$alert->id}/acknowledge", ['note' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/iot/alerts/{$alert->id}/acknowledge", ['note' => 'Pintu cold room terbuka'])->assertOk()->assertJsonPath('data.status', 'ACKNOWLEDGED');
        $this->postJson("/api/v1/iot/alerts/{$alert->id}/close", ['note' => 'Suhu normal kembali, stok diperiksa QC'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        // A new breach after closing is a new alert; with the stock already locked it only raises the alert.
        $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -30]]);
        $low = DB::table('telemetry_alerts')->where('kind', 'LOW')->first();
        $this->assertSame(0, (int) $low->locked_units);
        $this->assertNull($low->nonconformance_id);
    }

    public function test_mqtt_topics_map_to_devices_and_only_own_gateway_devices_are_reachable(): void
    {
        $g1 = $this->gateway('MQTT_BRIDGE');
        $g2 = $this->gateway();
        $d = $this->device(['gateway_id' => $g1['id'], 'mqtt_topic' => 'wh1/coldroom/temp', 'auto_lock' => false]);
        $this->assertSame(1, $this->ingest($g1['token'], [['topic' => 'wh1/coldroom/temp', 'value' => -17]])->json('data.accepted'));
        $this->assertSame(0, $this->ingest($g2['token'], [['topic' => 'wh1/coldroom/temp', 'value' => -17], ['device' => 'sensor-1', 'value' => -17]])->json('data.accepted')); // belongs to the other gateway
        $this->actingAs(User::first())->withSession(['tenant_id' => $this->tenant]);
        $this->assertCount(1, $this->getJson("/api/v1/iot/devices/{$d['id']}/readings")->json('data'));
        $this->postJson('/api/v1/iot/devices', ['code' => 'TH-2', 'name' => 'x', 'external_id' => 'sensor-2', 'metric' => 'TEMPERATURE', 'mqtt_topic' => 'wh1/coldroom/temp'])->assertUnprocessable(); // topic taken
        $this->postJson('/api/v1/iot/devices', ['code' => 'TH-3', 'name' => 'x', 'external_id' => 'sensor-3', 'metric' => 'TEMPERATURE', 'min_value' => 5, 'max_value' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/iot/devices', ['code' => 'TH-4', 'name' => 'x', 'external_id' => 'sensor-4', 'metric' => 'TEMPERATURE', 'auto_lock' => true])->assertUnprocessable(); // auto-lock needs a binding
        $this->putJson("/api/v1/iot/devices/{$d['id']}", ['name' => 'Sensor B', 'metric' => 'TEMPERATURE', 'min_value' => -22, 'max_value' => -16])->assertOk()->assertJsonPath('data.name', 'Sensor B');
    }

    public function test_a_silent_device_raises_a_stale_alert_that_closes_when_it_reports_again(): void
    {
        $g = $this->gateway();
        $this->device(['stale_after_minutes' => 15, 'auto_lock' => false]);
        $this->artisan('optistash:iot:check-stale')->expectsOutput('1 stale-device alert(s) raised.')->assertSuccessful(); // never reported
        $this->artisan('optistash:iot:check-stale')->expectsOutput('0 stale-device alert(s) raised.')->assertSuccessful(); // not twice
        $this->assertSame('STALE', DB::table('telemetry_alerts')->value('kind'));
        $this->ingest($g['token'], [['device' => 'sensor-1', 'value' => -18]]);
        $this->assertSame('CLOSED', DB::table('telemetry_alerts')->value('status'));
    }

    public function test_the_orchestrator_stays_standalone_or_delivers_signed_events_to_a_third_party(): void
    {
        config(['optistash.outbox.secret' => 'test-secret']);
        $this->putJson('/api/v1/integration/transport', ['mode' => 'THIRD_PARTY_API', 'api_url' => 'https://tms.example/hook'])->assertOk();
        $outboxId = (string) Str::uuid();
        DB::table('outbox_events')->insert(['id' => $outboxId, 'tenant_id' => $this->tenant, 'type' => 'shipment.dispatched.v1', 'payload' => json_encode(['data' => ['mode' => 'THIRD_PARTY_API']]), 'created_at' => now()]);
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'type' => 'master.uoms.created.v1', 'payload' => '{}', 'created_at' => now()]);

        $this->putJson('/api/v1/integration/standalone', ['standalone' => true])->assertOk();
        $status = 500;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });
        $this->postJson('/api/v1/integration/sync')->assertOk()->assertJsonPath('data.skipped', 'standalone')->assertJsonPath('data.delivered', 0);
        Http::assertNothingSent();

        $this->putJson('/api/v1/integration/standalone', ['standalone' => false])->assertOk();
        $r = $this->postJson('/api/v1/integration/sync')->assertOk()->json('data');
        $this->assertSame([0, 1], [$r['delivered'], $r['failed']]);
        $this->assertSame(1, (int) DB::table('outbox_events')->where('id', $outboxId)->value('attempts'));
        $this->assertSame('HTTP 500', DB::table('outbox_events')->where('id', $outboxId)->value('last_error'));

        $status = 200;
        $r = $this->postJson('/api/v1/integration/sync')->assertOk()->json('data'); // force retries at once
        $this->assertSame([1, 0], [$r['delivered'], $r['failed']]);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $outboxId)->value('published_at'));
        Http::assertSent(function ($request) use ($outboxId) {
            $ts = $request->header('Opti-Webhook-Timestamp')[0];

            return $request->url() === 'https://tms.example/hook' && $request->header('Opti-Webhook-Id')[0] === $outboxId
                && $request->header('Opti-Webhook-Signature')[0] === 'v1='.hash_hmac('sha256', $outboxId.'.'.$ts.'.'.$request->body(), 'test-secret');
        });
        $this->assertSame(0, DB::table('outbox_events')->where('type', 'master.uoms.created.v1')->whereNotNull('published_at')->count()); // only shipment/outbound events are routed
        $status = $this->getJson('/api/v1/integration/status')->assertOk()->json('data');
        $this->assertSame([0, 0, 1], [$status['outbox']['pending'], $status['outbox']['failed'], $status['outbox']['delivered']]);

        // Manual has nothing to deliver.
        $this->putJson('/api/v1/integration/transport', ['mode' => 'MANUAL'])->assertOk();
        $this->postJson('/api/v1/integration/sync')->assertJsonPath('data.skipped', 'manual');
        $this->assertDatabaseHas('audit_events', ['action' => 'integration.force_sync']);
    }

    public function test_optideli_mode_sends_a_pickup_request_through_the_optinexus_broker(): void
    {
        config(['optistash.optinexus.base_url' => 'http://nexus.test', 'optistash.optinexus.service.client_id' => 'svc', 'optistash.optinexus.service.client_secret' => 'secret']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->putJson('/api/v1/integration/transport', ['mode' => 'OPTIDELI'])->assertOk();
        $pickup = ['shipment_number' => 'SHP-1', 'warehouse' => ['code' => 'WH1', 'name' => 'G', 'address' => 'Jl. A', 'city' => 'Bekasi'], 'items' => [['product_code' => 'ICE', 'product_name' => 'Es', 'quantity' => 5, 'uom' => 'PCS']]];
        $dispatched = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('outbox_events')->insert(['id' => $dispatched, 'tenant_id' => $this->tenant, 'type' => 'shipment.dispatched.v1', 'payload' => json_encode(['subject_id' => 'S1', 'occurred_at' => '2026-11-09T10:00:00+00:00', 'data' => ['pickup' => $pickup]]), 'created_at' => now()]);
        DB::table('outbox_events')->insert(['id' => $other, 'tenant_id' => $this->tenant, 'type' => 'shipment.created.v1', 'payload' => '{}', 'created_at' => now()->subSecond()]);

        $status = 503;
        Http::fake(['nexus.test/api/v1/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), 'nexus.test/api/v1/events' => function () use (&$status) {
            return Http::response([], $status);
        }]);
        // Not linked to OptiNexus: nothing leaves.
        $this->postJson('/api/v1/integration/sync')->assertJsonPath('data.skipped', 'tenant-not-linked-to-optinexus');
        Http::assertNothingSent();

        $nexus = (string) Str::uuid();
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => $nexus]);
        $r = $this->postJson('/api/v1/integration/sync')->assertOk()->json('data');
        $this->assertSame([0, 1], [$r['delivered'], $r['failed']]);
        $this->assertSame('HTTP 503', DB::table('outbox_events')->where('id', $dispatched)->value('last_error'));

        $status = 202;
        $r = $this->postJson('/api/v1/integration/sync')->assertOk()->json('data');
        $this->assertSame([1, 0], [$r['delivered'], $r['failed']]);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $dispatched)->value('published_at'));
        $this->assertNotNull(DB::table('outbox_events')->where('id', $other)->value('published_at')); // nothing for a carrier: closed without sending
        Http::assertSent(fn ($request) => $request->url() === 'http://nexus.test/api/v1/events' && $request['event_id'] === $dispatched && $request['event_key'] === 'optistash.pickup.requested'
            && $request['tenant_id'] === $nexus && $request['data']['aggregate_type'] === 'shipment' && $request['data']['payload']['warehouse']['city'] === 'Bekasi' && $request['data']['payload']['items'][0]['quantity'] === 5);
    }

    public function test_only_integration_managers_control_it(): void
    {
        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['tenant_id' => $this->tenant, 'user_id' => $viewer->id, 'role' => 'quality_auditor', 'active' => true]); // integration.view only
        $this->actingAs($viewer)->withSession(['tenant_id' => $this->tenant]);
        $this->getJson('/api/v1/integration/status')->assertOk();
        $this->getJson('/api/v1/iot/devices')->assertOk();
        $this->postJson('/api/v1/iot/gateways', ['name' => 'x', 'protocol' => 'REST'])->assertForbidden();
        $this->putJson('/api/v1/integration/standalone', ['standalone' => true])->assertForbidden();
        $this->postJson('/api/v1/integration/sync')->assertForbidden();
    }
}
