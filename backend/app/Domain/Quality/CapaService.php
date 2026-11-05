<?php

namespace App\Domain\Quality;

use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Non-conformances (manual records beside the ones the receiving raises) and CAPA, ISO 9001:2015 clauses 8.7 and 10.2.
 * A CAPA moves OPEN -> IN_PROGRESS (root cause analysis recorded) -> VERIFIED (effectiveness checked by someone other than its
 * owner) -> CLOSED. Closing needs every action done. A corrective action cannot start without a root cause.
 */
final class CapaService
{
    public const NC_SOURCES = ['STOCKTAKE', 'OUTBOUND', 'AUDIT', 'OTHER'];

    public const NC_CATEGORIES = ['DAMAGED', 'EXPIRED', 'WRONG_ITEM', 'QUANTITY', 'TEMPERATURE', 'OTHER'];

    public const FISHBONE = ['MAN', 'MACHINE', 'METHOD', 'MATERIAL', 'MEASUREMENT', 'ENVIRONMENT'];

    public function __construct(private readonly TenantStore $store) {}

    public function raiseNonconformance(string $source, string $category, string $title, string $description): object
    {
        abort_unless(in_array($source, self::NC_SOURCES, true) && in_array($category, self::NC_CATEGORIES, true), 422, 'Sumber atau kategori tidak dikenal.');

        return DB::transaction(function () use ($source, $category, $title, $description) {
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('NC-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'NC', $period));
            DB::table('nonconformances')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'source' => $source, 'category' => $category, 'title' => $title,
                'description' => $description, 'disposition' => 'QUARANTINE', 'status' => 'OPEN', 'raised_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('nonconformance.raised', $id, ['number' => $number, 'source' => $source, 'category' => $category]);

            return $this->store->find('nonconformances', $id);
        }, 3);
    }

    public function createCapa(array $d): object
    {
        abort_unless(in_array($d['type'], ['CORRECTIVE', 'PREVENTIVE'], true), 422, 'Jenis CAPA tidak dikenal.');
        abort_if($d['due_date'] < now()->toDateString(), 422, 'Tenggat tidak boleh di masa lalu.');

        return DB::transaction(function () use ($d) {
            if (! empty($d['nonconformance_id'])) {
                $this->store->find('nonconformances', $d['nonconformance_id']);
            }
            abort_unless(DB::table('memberships')->where('tenant_id', $this->store->tenantId)->where('user_id', $d['owner_id'])->where('active', true)->exists(), 422, 'Penanggung jawab bukan anggota aktif tenant ini.');
            $id = (string) Str::uuid();
            $period = now()->format('Ym');
            $number = sprintf('CA-%s%05d', $period, DocumentNumbers::next($this->store->tenantId, 'CA', $period));
            DB::table('capas')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'number' => $number, 'nonconformance_id' => $d['nonconformance_id'] ?? null, 'type' => $d['type'],
                'title' => $d['title'], 'description' => $d['description'], 'owner_id' => $d['owner_id'], 'due_date' => $d['due_date'], 'created_by' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('capa.created', $id, ['number' => $number, 'type' => $d['type']]);

            return $this->one($id);
        }, 3);
    }

    /**
     * Records the root cause analysis. FIVE_WHY: a chain of 3 to 7 "why" answers ending in the root cause. FISHBONE: causes per category
     * (man, machine, method, material, measurement, environment) with the one chosen as root cause. Starts the CAPA.
     */
    public function analyse(string $id, string $method, array $rca, string $rootCause): object
    {
        abort_unless(in_array($method, ['FIVE_WHY', 'FISHBONE'], true), 422, 'Metode analisis tidak dikenal.');
        if ($method === 'FIVE_WHY') {
            $whys = array_values(array_filter(array_map('trim', $rca['whys'] ?? [])));
            abort_unless(count($whys) >= 3 && count($whys) <= 7, 422, '5-Why memerlukan 3 sampai 7 jawaban berantai.');
            $rca = ['whys' => $whys];
        } else {
            $causes = [];
            foreach (self::FISHBONE as $cat) {
                $list = array_values(array_filter(array_map('trim', $rca['causes'][$cat] ?? [])));
                if ($list !== []) {
                    $causes[$cat] = $list;
                }
            }
            abort_if($causes === [], 422, 'Fishbone memerlukan sedikitnya satu penyebab.');
            abort_if(array_diff(array_keys($rca['causes'] ?? []), self::FISHBONE) !== [], 422, 'Kategori fishbone tidak dikenal.');
            $rca = ['causes' => $causes];
        }
        abort_if(mb_strlen(trim($rootCause)) < 5, 422, 'Akar masalah minimal 5 karakter.');

        return DB::transaction(function () use ($id, $method, $rca, $rootCause) {
            $c = $this->store->find('capas', $id, true);
            abort_unless(in_array($c->status, ['OPEN', 'IN_PROGRESS'], true), 422, 'CAPA ini sudah diverifikasi atau ditutup.');
            DB::table('capas')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['rca_method' => $method, 'rca' => json_encode($rca), 'root_cause' => trim($rootCause), 'status' => 'IN_PROGRESS', 'updated_at' => now()]);
            $this->store->event('capa.analysed', $id, ['number' => $c->number, 'method' => $method]);

            return $this->one($id);
        }, 3);
    }

    public function addAction(string $capaId, string $description, int $assignee, string $due): object
    {
        return DB::transaction(function () use ($capaId, $description, $assignee, $due) {
            $c = $this->store->find('capas', $capaId, true);
            abort_unless($c->status === 'IN_PROGRESS', 422, 'Tindakan hanya dapat ditambahkan setelah akar masalah dianalisis dan sebelum verifikasi.');
            abort_unless(DB::table('memberships')->where('tenant_id', $this->store->tenantId)->where('user_id', $assignee)->where('active', true)->exists(), 422, 'Penerima tugas bukan anggota aktif tenant ini.');
            abort_if($due < now()->toDateString(), 422, 'Tenggat tidak boleh di masa lalu.');
            DB::table('capa_actions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'capa_id' => $capaId, 'description' => $description, 'assignee_id' => $assignee, 'due_date' => $due, 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event('capa.action_added', $capaId, ['number' => $c->number]);

            return $this->one($capaId);
        }, 3);
    }

    public function completeAction(string $actionId): object
    {
        return DB::transaction(function () use ($actionId) {
            $a = $this->store->find('capa_actions', $actionId, true);
            abort_if($a->done_at !== null, 422, 'Tindakan sudah selesai.');
            abort_unless($a->assignee_id === $this->store->actorId, 403, 'Hanya penerima tugas yang dapat menyelesaikannya.');
            DB::table('capa_actions')->where('tenant_id', $this->store->tenantId)->where('id', $actionId)->update(['done_at' => now(), 'updated_at' => now()]);
            $this->store->event('capa.action_done', $a->capa_id, []);

            return $this->one($a->capa_id);
        }, 3);
    }

    /** Effectiveness check by someone other than the owner. */
    public function verify(string $id, string $notes): object
    {
        abort_if(mb_strlen(trim($notes)) < 5, 422, 'Catatan verifikasi minimal 5 karakter.');

        return DB::transaction(function () use ($id, $notes) {
            $c = $this->store->find('capas', $id, true);
            abort_unless($c->status === 'IN_PROGRESS', 422, 'Hanya CAPA yang sedang berjalan yang dapat diverifikasi.');
            abort_if($c->owner_id === $this->store->actorId, 403, 'Penanggung jawab tidak dapat memverifikasi CAPA-nya sendiri.');
            $actions = $this->store->query('capa_actions')->where('capa_id', $id)->get();
            abort_if($actions->isEmpty() || $actions->contains(fn ($a) => $a->done_at === null), 422, 'Semua tindakan harus ada dan selesai sebelum verifikasi.');
            DB::table('capas')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'VERIFIED', 'verification_notes' => trim($notes), 'verified_by' => $this->store->actorId, 'verified_at' => now(), 'updated_at' => now()]);
            $this->store->event('capa.verified', $id, ['number' => $c->number]);

            return $this->one($id);
        }, 3);
    }

    public function close(string $id): object
    {
        return DB::transaction(function () use ($id) {
            $c = $this->store->find('capas', $id, true);
            abort_unless($c->status === 'VERIFIED', 422, 'Hanya CAPA yang sudah diverifikasi yang dapat ditutup.');
            DB::table('capas')->where('tenant_id', $this->store->tenantId)->where('id', $id)->update(['status' => 'CLOSED', 'closed_at' => now(), 'updated_at' => now()]);
            if ($c->nonconformance_id) {
                DB::table('nonconformances')->where('tenant_id', $this->store->tenantId)->where('id', $c->nonconformance_id)->where('status', 'OPEN')->where('source', '!=', 'RECEIPT')
                    ->update(['status' => 'CLOSED', 'decided_by' => $this->store->actorId, 'decided_at' => now(), 'updated_at' => now()]);
            }
            $this->store->event('capa.closed', $id, ['number' => $c->number]);

            return $this->one($id);
        }, 3);
    }

    public function one(string $id): object
    {
        $c = $this->store->find('capas', $id);
        $c->rca = $c->rca ? json_decode($c->rca, true) : null;
        $c->overdue = $c->status !== 'CLOSED' && $c->status !== 'VERIFIED' && $c->due_date < now()->toDateString();
        $c->actions = DB::table('capa_actions as a')->where('a.tenant_id', $this->store->tenantId)->where('a.capa_id', $id)->join('users as u', 'u.id', '=', 'a.assignee_id')
            ->orderBy('a.due_date')->get(['a.id', 'a.description', 'a.assignee_id', 'u.name as assignee_name', 'a.due_date', 'a.done_at'])->all();

        return $c;
    }

    public function list(?string $status): array
    {
        return $this->store->query('capas')->when($status, fn ($q) => $q->where('status', $status))->orderBy('due_date')->limit(200)->get()
            ->each(fn ($c) => $c->overdue = ! in_array($c->status, ['CLOSED', 'VERIFIED'], true) && $c->due_date < now()->toDateString())->all();
    }
}
