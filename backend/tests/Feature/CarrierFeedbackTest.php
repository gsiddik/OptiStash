<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInTenantMember;
use Tests\TestCase;

/** The carrier system (OptiDeli) reports what happened to a pickup request: shown on the shipment, kept as an append-only history. */
class CarrierFeedbackTest extends TestCase
{
    use RefreshDatabase, SignsInTenantMember;

    private const SECRET = 'whsec_test_secret';

    private const NEXUS_TENANT = '6f1d2c3b-1111-4a5b-8c9d-0123456789ab';

    private string $tenant;

    private string $shipment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['optistash.optinexus.events.webhook_secret' => self::SECRET]);
        $this->tenant = $this->signInTenantMember('warehouse_manager', 'gudang');
        DB::table('tenants')->where('id', $this->tenant)->update(['optinexus_tenant_id' => self::NEXUS_TENANT]);
        $warehouse = $this->postJson('/api/v1/master/warehouses', ['code' => 'WH1', 'name' => 'Gudang', 'granularity' => 'BIN'])->json('data.id');
        $this->shipment = $this->shipment($warehouse, 'SHP-000001', 'OPTIDELI');
    }

    private function shipment(string $warehouse, string $number, string $mode, ?string $tenant = null): string
    {
        $id = (string) Str::uuid();
        DB::table('shipments')->insert(['id' => $id, 'tenant_id' => $tenant ?? $this->tenant, 'number' => $number, 'warehouse_id' => $warehouse, 'mode' => $mode, 'status' => 'DISPATCHED',
            'dispatched_at' => now(), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function event(string $status, string $at, string $number = 'SHP-000001', ?string $id = null, ?string $reason = null): array
    {
        return [
            'event_id' => $id ?? (string) Str::uuid(), 'event_type' => 'optideli.pickup.status_changed', 'schema_version' => '1', 'source_system' => 'optideli',
            'tenant_id' => self::NEXUS_TENANT, 'occurred_at' => $at, 'correlation_id' => (string) Str::uuid(),
            'data' => ['aggregate_type' => 'shipment', 'aggregate_id' => (string) Str::uuid(), 'correlation' => [], 'payload' => array_filter([
                'shipment_number' => $number, 'status' => $status, 'occurred_at' => $at, 'reason' => $reason,
            ])],
        ];
    }

    private function deliver(array $event, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $id = (string) Str::uuid();
        $ts = (string) time();

        return $this->call('POST', '/api/v1/integration/optinexus/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_OPTI_WEBHOOK_ID' => $id, 'HTTP_OPTI_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_OPTI_WEBHOOK_SIGNATURE' => 'v1='.hash_hmac('sha256', $id.'.'.$ts.'.'.$body, $secret),
        ], $body);
    }

    private function current(): object
    {
        return DB::table('shipments')->where('id', $this->shipment)->first(['carrier_status', 'carrier_reason']);
    }

    public function test_the_latest_status_shows_on_the_shipment_with_its_history(): void
    {
        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00'), 'wrong-secret')->assertUnauthorized();
        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00'))->assertStatus(202)->assertJson(['result' => 'applied']);
        $this->deliver($this->event('PICKED_UP', '2026-11-10T10:00:00+00:00'))->assertJson(['result' => 'applied']);
        $this->deliver($this->event('DELIVERED', '2026-11-10T15:30:00+00:00'))->assertJson(['result' => 'applied']);

        $this->assertSame('DELIVERED', $this->current()->carrier_status);
        $list = $this->getJson('/api/v1/shipments')->assertOk()->json('data');
        $this->assertCount(1, $list);
        // The list carries what its screen reads: the orders (none here) and the carrier history, newest first.
        $this->assertSame([], $list[0]['orders']);
        $this->assertSame(['DELIVERED', 'PICKED_UP', 'ACCEPTED'], array_column($list[0]['carrier_events'], 'status'));
        $this->assertSame('DELIVERED', $list[0]['carrier_status']);
    }

    public function test_a_repeated_or_older_event_never_overwrites_a_newer_status(): void
    {
        $picked = $this->event('PICKED_UP', '2026-11-10T10:00:00+00:00');
        $this->deliver($picked)->assertJson(['result' => 'applied']);
        $this->deliver($picked)->assertJson(['result' => 'duplicate']);
        // Arrives late: kept in the history, the shipment keeps the newer status.
        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00'))->assertJson(['result' => 'stale']);

        $this->assertSame('PICKED_UP', $this->current()->carrier_status);
        $this->assertSame(2, DB::table('shipment_carrier_events')->count());
    }

    public function test_a_rejection_keeps_its_reason_and_the_history_cannot_be_changed(): void
    {
        $this->deliver($this->event('REJECTED', '2026-11-10T09:00:00+00:00', reason: 'Armada penuh'))->assertJson(['result' => 'applied']);
        $this->assertSame(['REJECTED', 'Armada penuh'], [$this->current()->carrier_status, $this->current()->carrier_reason]);

        $this->expectException(QueryException::class);
        DB::table('shipment_carrier_events')->update(['status' => 'DELIVERED']);
    }

    public function test_unusable_unknown_and_foreign_events_change_nothing(): void
    {
        $this->deliver($this->event('FLYING', '2026-11-10T08:00:00+00:00'))->assertStatus(422)->assertJson(['code' => 'CARRIER_STATUS_INVALID']);
        $this->deliver($this->event('ACCEPTED', 'not a time'))->assertStatus(422);
        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00', id: 'not-a-uuid'))->assertStatus(422);

        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00', 'SHP-NOPE'))->assertStatus(202)->assertJson(['result' => 'ignored']);
        $unknownTenant = $this->event('ACCEPTED', '2026-11-10T08:00:00+00:00');
        $unknownTenant['tenant_id'] = '11111111-2222-4333-8444-555555555555';
        $this->deliver($unknownTenant)->assertJson(['result' => 'ignored']);
        $other = $this->event('ACCEPTED', '2026-11-10T08:00:00+00:00');
        $other['source_system'] = 'optifleet';
        $this->deliver($other)->assertJson(['result' => 'ignored']);

        // Only a shipment that really went to OptiDeli listens, and only in the tenant the event names.
        $warehouse = (string) DB::table('warehouses')->where('tenant_id', $this->tenant)->value('id');
        $this->shipment($warehouse, 'SHP-000002', 'MANUAL');
        $this->deliver($this->event('ACCEPTED', '2026-11-10T08:00:00+00:00', 'SHP-000002'))->assertJson(['result' => 'ignored']);
        $this->assertNull($this->current()->carrier_status);
        $this->assertSame(0, DB::table('shipment_carrier_events')->count());
    }
}
