<?php

namespace App\Domain\Inbound;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Dock appointments of confirmed ASNs, and the gate-in and gate-out of the trucks that keep them. A dock hosts one truck at a time. */
final class DockService
{
    public const MAX_HOURS = 12;

    public function __construct(private readonly TenantStore $store) {}

    public function book(string $dockId, string $asnId, string $start, string $end, ?string $plate): object
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        abort_unless($to->greaterThan($from), 422, 'Waktu selesai harus setelah waktu mulai.');
        abort_if($from->diffInMinutes($to) > self::MAX_HOURS * 60, 422, 'Satu slot maksimal '.self::MAX_HOURS.' jam.');

        return DB::transaction(function () use ($dockId, $asnId, $from, $to, $plate) {
            DB::table('tenants')->where('id', $this->store->tenantId)->lockForUpdate()->firstOrFail();
            $dock = $this->store->find('docks', $dockId);
            abort_unless($dock->active, 422, 'Dock tidak aktif.');
            $asn = $this->store->find('asns', $asnId, true);
            abort_unless($asn->status === 'CONFIRMED', 422, 'Hanya ASN yang sudah dikonfirmasi yang dapat dijadwalkan.');
            abort_unless($asn->warehouse_id === $dock->warehouse_id, 422, 'Dock bukan milik gudang tujuan ASN.');
            abort_if($this->store->query('dock_bookings')->where('asn_id', $asnId)->whereIn('status', ['BOOKED', 'GATE_IN'])->exists(), 422, 'ASN ini sudah memiliki jadwal dock.');
            $clash = $this->store->query('dock_bookings')->where('dock_id', $dockId)->whereIn('status', ['BOOKED', 'GATE_IN'])
                ->where('slot_start', '<', $to)->where('slot_end', '>', $from)->exists();
            abort_if($clash, 422, 'Slot dock bentrok dengan jadwal lain.');
            $id = (string) Str::uuid();
            DB::table('dock_bookings')->insert([
                'id' => $id, 'tenant_id' => $this->store->tenantId, 'dock_id' => $dockId, 'asn_id' => $asnId, 'slot_start' => $from, 'slot_end' => $to,
                'truck_plate' => $plate ?? $asn->truck_plate, 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->store->event('dock.booked', $id, ['asn' => $asn->number, 'dock' => $dock->code, 'start' => $from->toIso8601String()]);

            return $this->store->find('dock_bookings', $id);
        }, 3);
    }

    public function cancel(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan pembatalan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $b = $this->store->find('dock_bookings', $id, true);
            abort_unless($b->status === 'BOOKED', 422, 'Hanya jadwal yang belum gate-in yang dapat dibatalkan.');
            DB::table('dock_bookings')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $this->store->event('dock.cancelled', $id, ['reason' => trim($reason)]);

            return $this->store->find('dock_bookings', $id);
        }, 3);
    }

    /** The truck is at the gate: the booking becomes GATE_IN and its ASN ARRIVED, which opens receiving. */
    public function gateIn(string $id, ?string $plate): object
    {
        return DB::transaction(function () use ($id, $plate) {
            $b = $this->store->find('dock_bookings', $id, true);
            abort_unless($b->status === 'BOOKED', 422, 'Gate-in hanya untuk jadwal berstatus BOOKED.');
            $asn = $this->store->find('asns', $b->asn_id, true);
            abort_unless($asn->status === 'CONFIRMED', 422, 'ASN tidak lagi dalam status CONFIRMED.');
            DB::table('dock_bookings')->where('tenant_id', $this->store->tenantId)->where('id', $id)
                ->update(['status' => 'GATE_IN', 'gate_in_at' => now(), 'truck_plate' => $plate ?? $b->truck_plate, 'updated_at' => now()]);
            DB::table('asns')->where('tenant_id', $this->store->tenantId)->where('id', $asn->id)->update(['status' => 'ARRIVED', 'updated_at' => now()]);
            $this->store->event('dock.gate_in', $id, ['asn' => $asn->number, 'plate' => $plate ?? $b->truck_plate]);

            return $this->store->find('dock_bookings', $id);
        }, 3);
    }

    public function gateOut(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $b = $this->store->find('dock_bookings', $id, true);
            abort_unless($b->status === 'GATE_IN', 422, 'Gate-out hanya untuk truk yang sudah gate-in.');
            DB::table('dock_bookings')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'GATE_OUT', 'gate_out_at' => now(), 'updated_at' => now()]);
            $this->store->event('dock.gate_out', $id, []);

            return $this->store->find('dock_bookings', $id);
        }, 3);
    }

    /** Bookings that start on or after $from (default: today), with dock and ASN labels. */
    public function list(?string $from, ?string $status): array
    {
        return DB::table('dock_bookings as b')->where('b.tenant_id', $this->store->tenantId)
            ->join('docks as d', fn ($j) => $j->on('d.id', '=', 'b.dock_id')->on('d.tenant_id', '=', 'b.tenant_id'))
            ->leftJoin('asns as a', fn ($j) => $j->on('a.id', '=', 'b.asn_id')->on('a.tenant_id', '=', 'b.tenant_id'))
            ->where('b.slot_start', '>=', CarbonImmutable::parse($from ?? 'today'))->when($status, fn ($q) => $q->where('b.status', $status))
            ->orderBy('b.slot_start')->limit(200)
            ->get(['b.id', 'b.status', 'b.slot_start', 'b.slot_end', 'b.truck_plate', 'b.gate_in_at', 'b.gate_out_at', 'd.code as dock_code', 'a.number as asn_number', 'a.id as asn_id'])->all();
    }
}
