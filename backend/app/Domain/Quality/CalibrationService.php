<?php

namespace App\Domain\Quality;

use App\Domain\Shared\TenantStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Measuring equipment and its calibration (ISO 9001:2015 clause 7.1.5). An instrument is usable only while it is active, its last
 * calibration passed, and the next due date has not passed; everything that measures (for example receiving of chilled goods)
 * refuses an instrument that is not usable.
 */
final class CalibrationService
{
    public const KINDS = ['THERMOMETER', 'SCALE', 'HYGROMETER', 'OTHER'];

    public function __construct(private readonly TenantStore $store) {}

    public function register(string $code, string $name, string $kind, ?string $warehouseId, int $intervalDays): object
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Jenis alat tidak dikenal.');
        abort_if($intervalDays < 1 || $intervalDays > 1825, 422, 'Interval kalibrasi 1 sampai 1825 hari.');

        return DB::transaction(function () use ($code, $name, $kind, $warehouseId, $intervalDays) {
            $code = Str::upper(trim($code));
            abort_if($this->store->query('instruments')->where('code', $code)->exists(), 422, "Kode alat {$code} sudah dipakai.");
            if ($warehouseId !== null) {
                $this->store->find('warehouses', $warehouseId);
            }
            $id = (string) Str::uuid();
            DB::table('instruments')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'code' => $code, 'name' => $name, 'kind' => $kind, 'warehouse_id' => $warehouseId, 'interval_days' => $intervalDays, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('instrument.registered', $id, ['code' => $code]);

            return $this->one($id);
        }, 3);
    }

    /** Records a calibration. A PASS sets the next due date from the interval (or the date given by the certificate); a FAIL takes the instrument out of use. */
    public function record(string $instrumentId, string $on, string $by, string $certificate, string $result, ?string $nextDue, ?string $notes): object
    {
        abort_unless(in_array($result, ['PASS', 'FAIL'], true), 422, 'Hasil harus PASS atau FAIL.');
        abort_if($on > now()->toDateString(), 422, 'Tanggal kalibrasi tidak boleh di masa depan.');

        return DB::transaction(function () use ($instrumentId, $on, $by, $certificate, $result, $nextDue, $notes) {
            $i = $this->store->find('instruments', $instrumentId, true);
            abort_unless($i->active, 422, 'Alat sudah dinonaktifkan.');
            $due = $result === 'PASS' ? ($nextDue ?? CarbonImmutable::parse($on)->addDays($i->interval_days)->toDateString()) : null;
            abort_if($due !== null && $due <= $on, 422, 'Jatuh tempo berikutnya harus setelah tanggal kalibrasi.');
            DB::table('calibrations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'instrument_id' => $instrumentId, 'calibrated_on' => $on, 'performed_by' => $by, 'certificate_no' => $certificate,
                'result' => $result, 'next_due_on' => $due, 'notes' => $notes, 'recorded_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('instruments')->where('tenant_id', $this->store->tenantId)->where('id', $instrumentId)->update(['last_calibrated_on' => $on, 'next_due_on' => $due, 'updated_at' => now()]);
            $this->store->event('instrument.calibrated', $instrumentId, ['code' => $i->code, 'result' => $result, 'certificate' => $certificate]);

            return $this->one($instrumentId);
        }, 3);
    }

    public function deactivate(string $id, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($id, $reason) {
            $i = $this->store->find('instruments', $id, true);
            DB::table('instruments')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            $this->store->event('instrument.deactivated', $id, ['code' => $i->code, 'reason' => trim($reason)]);

            return $this->one($id);
        }, 3);
    }

    /** @return array{usable: bool, reason: ?string} */
    public static function usability(object $i): array
    {
        if (! $i->active) {
            return ['usable' => false, 'reason' => 'Alat tidak aktif.'];
        }
        if ($i->next_due_on === null) {
            return ['usable' => false, 'reason' => $i->last_calibrated_on === null ? 'Alat belum pernah dikalibrasi.' : 'Kalibrasi terakhir gagal.'];
        }
        if ($i->next_due_on < now()->toDateString()) {
            return ['usable' => false, 'reason' => "Kalibrasi kedaluwarsa sejak {$i->next_due_on}."];
        }

        return ['usable' => true, 'reason' => null];
    }

    /** Aborts (422) unless the instrument may be used now. Called by every process that measures. */
    public function assertUsable(string $instrumentId): object
    {
        $i = $this->store->query('instruments')->where('id', $instrumentId)->first();
        abort_unless($i, 422, 'Alat ukur tidak ditemukan.');
        $u = self::usability($i);
        abort_unless($u['usable'], 422, "Alat ukur {$i->code} tidak boleh dipakai: {$u['reason']}");

        return $i;
    }

    public function one(string $id): object
    {
        $i = $this->store->find('instruments', $id);
        $i->usability = self::usability($i);
        $i->history = $this->store->query('calibrations')->where('instrument_id', $id)->orderByDesc('calibrated_on')->orderByDesc('created_at')->limit(50)->get()->all();

        return $i;
    }

    public function list(): array
    {
        return $this->store->query('instruments')->orderBy('code')->get()->each(fn ($i) => $i->usability = self::usability($i))->all();
    }
}
