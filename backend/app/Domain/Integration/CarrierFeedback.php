<?php

namespace App\Domain\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the carrier system (OptiDeli) tells OptiStash about a shipment that was sent to it as a pickup request: accepted, rejected, picked up,
 * delivered or cancelled. It is information for the warehouse; it does not move stock or change the shipment's own status. Every event is
 * kept (append-only history, idempotent by event id); the shipment shows the latest one by the time OptiDeli says it happened.
 */
final class CarrierFeedback
{
    public const RECEIVED = 'optideli.pickup.status_changed';

    public const STATUSES = ['ACCEPTED', 'REJECTED', 'PICKED_UP', 'DELIVERED', 'CANCELLED'];

    /** @return 'applied'|'stale'|'duplicate'|'ignored'|'invalid' */
    public static function apply(array $event): string
    {
        if (($event['event_type'] ?? null) !== self::RECEIVED || ($event['source_system'] ?? null) !== 'optideli') {
            return 'ignored';
        }
        $tenant = DB::table('tenants')->where('optinexus_tenant_id', (string) ($event['tenant_id'] ?? ''))->where('active', true)->first();
        if (! $tenant) {
            return 'ignored';
        }
        $p = $event['data']['payload'] ?? null;
        $eventId = (string) ($event['event_id'] ?? '');
        $number = is_array($p) && is_string($p['shipment_number'] ?? null) ? mb_substr(trim($p['shipment_number']), 0, 40) : '';
        $status = is_array($p) ? ($p['status'] ?? null) : null;
        $at = self::time(is_array($p) ? ($p['occurred_at'] ?? null) : null) ?? self::time($event['occurred_at'] ?? null);
        if (! Str::isUuid($eventId) || $number === '' || ! in_array($status, self::STATUSES, true) || $at === null) {
            return 'invalid';
        }
        $reason = is_string($p['reason'] ?? null) && trim($p['reason']) !== '' ? mb_substr(trim($p['reason']), 0, 300) : null;

        return DB::transaction(function () use ($tenant, $eventId, $number, $status, $at, $reason) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('tenant_id', $tenant->id)->where('number', $number)->where('mode', 'OPTIDELI')->lockForUpdate()->first();
            if (! $shipment) {
                return 'ignored';
            }
            if (DB::table('shipment_carrier_events')->where('tenant_id', $tenant->id)->where('source_event_id', $eventId)->exists()) {
                return 'duplicate';
            }
            DB::table('shipment_carrier_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'shipment_id' => $shipment->id, 'source_event_id' => $eventId,
                'status' => $status, 'reason' => $reason, 'occurred_at' => $at, 'received_at' => now()]);
            if ($shipment->carrier_status_at !== null && CarbonImmutable::parse($shipment->carrier_status_at)->greaterThan($at)) {
                return 'stale';
            }
            DB::table('shipments')->where('id', $shipment->id)->update(['carrier_status' => $status, 'carrier_status_at' => $at, 'carrier_reason' => $reason, 'updated_at' => now()]);

            return 'applied';
        }, 3);
    }

    private static function time(mixed $v): ?string
    {
        try {
            return is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->toIso8601String() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
