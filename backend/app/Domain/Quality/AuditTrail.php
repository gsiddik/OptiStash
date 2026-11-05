<?php

namespace App\Domain\Quality;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;

/**
 * Read access to the immutable audit trail (audit_events, append-only by database trigger) and the ISO 9001:2015 clause map: which
 * clause each function of OptiStash gives evidence for, with live figures drawn from the data.
 */
final class AuditTrail
{
    public const EXPORT_LIMIT = 5000;

    public function __construct(private readonly TenantStore $store) {}

    private function filtered(array $f)
    {
        $q = DB::table('audit_events as e')->where('e.tenant_id', $this->store->tenantId)->join('users as u', 'u.id', '=', 'e.actor_id');
        if (! empty($f['action'])) {
            $q->where('e.action', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['action']).'%');
        }
        if (! empty($f['actor_id'])) {
            $q->where('e.actor_id', (int) $f['actor_id']);
        }
        if (! empty($f['subject_id'])) {
            $q->where('e.subject_id', $f['subject_id']);
        }
        if (! empty($f['from'])) {
            $q->where('e.created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('e.created_at', '<', date('Y-m-d', strtotime($f['to'].' +1 day')));
        }

        return $q;
    }

    public function search(array $filters, int $page, int $perPage = 50): array
    {
        $q = $this->filtered($filters);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('e.created_at')->orderByDesc('e.id')->forPage($page, $perPage)->get(['e.id', 'e.action', 'e.subject_id', 'e.metadata', 'e.created_at', 'e.actor_id', 'u.name as actor_name']);

        return ['data' => $rows->map(fn ($r) => tap($r, fn ($x) => $x->metadata = json_decode($x->metadata, true)))->all(), 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total];
    }

    /** @return \Generator<list<string>> CSV rows (header first) of the filtered trail, oldest first, up to EXPORT_LIMIT. */
    public function export(array $filters): \Generator
    {
        yield ['waktu', 'aktor', 'aksi', 'subjek', 'metadata'];
        foreach ($this->filtered($filters)->orderBy('e.created_at')->orderBy('e.id')->limit(self::EXPORT_LIMIT)->get(['e.created_at', 'u.name', 'e.action', 'e.subject_id', 'e.metadata']) as $r) {
            yield [$r->created_at, $this->safe($r->name), $r->action, $r->subject_id, $this->safe((string) $r->metadata)];
        }
    }

    /** Neutralizes spreadsheet formulas in exported text. */
    private function safe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }

    /** @return list<array{clause:string,title:string,evidence:string,metrics:array<string,int|string>}> */
    public function isoMap(): array
    {
        $t = $this->store->tenantId;
        $count = fn (string $table, ?callable $where = null) => (int) DB::table($table)->where('tenant_id', $t)->when($where, fn ($q) => $where($q))->count();
        $today = now()->toDateString();
        $events = fn (string $like) => (int) DB::table('audit_events')->where('tenant_id', $t)->where('action', 'like', $like)->count();

        return [
            ['clause' => '7.1.5', 'title' => 'Sumber daya pemantauan dan pengukuran', 'evidence' => 'Registri alat ukur dan kalibrasi; alat kedaluwarsa diblokir dari penerimaan barang berpendingin.',
                'metrics' => ['alat' => $count('instruments'), 'kalibrasi_kedaluwarsa' => $count('instruments', fn ($q) => $q->where('active', true)->where(fn ($w) => $w->whereNull('next_due_on')->orWhere('next_due_on', '<', $today)))]],
            ['clause' => '7.5', 'title' => 'Informasi terdokumentasi', 'evidence' => 'Dokumen terkendali dengan versi, persetujuan oleh orang lain, dan checksum; jejak audit tidak dapat diubah.',
                'metrics' => ['dokumen_berlaku' => $count('document_versions', fn ($q) => $q->where('status', 'EFFECTIVE')), 'peristiwa_audit' => $count('audit_events')]],
            ['clause' => '8.4', 'title' => 'Pengendalian proses yang disediakan pihak luar', 'evidence' => 'Pemasok terdaftar dengan portal sendiri; setiap kedatangan dicatat sebagai ASN dan diperiksa saat diterima.',
                'metrics' => ['pemasok' => $count('partners', fn ($q) => $q->where('type', 'SUPPLIER')), 'asn' => $count('asns')]],
            ['clause' => '8.5.2', 'title' => 'Identifikasi dan mampu telusur', 'evidence' => 'Setiap palet/karton memiliki LPN dengan lot dan kedaluwarsa; semua perubahan tercatat di buku besar pergerakan.',
                'metrics' => ['lpn' => $count('stock_units'), 'pergerakan' => $count('stock_movements')]],
            ['clause' => '8.5.4', 'title' => 'Pemeliharaan', 'evidence' => 'Putaway terarah, rotasi FEFO/FIFO/LIFO, kelas suhu, dan blokir lokasi.',
                'metrics' => ['putaway_selesai' => $count('putaway_tasks', fn ($q) => $q->where('status', 'DONE')), 'lokasi_diblokir' => $count('storage_nodes', fn ($q) => $q->where('blocked', true))]],
            ['clause' => '8.6', 'title' => 'Pelepasan produk dan layanan', 'evidence' => 'Keputusan QC (lepas, karantina, tolak) dengan alasan; verifikasi packing sebelum pengiriman.',
                'metrics' => ['keputusan_qc' => $events('qc.decision'), 'pengiriman_terkirim' => $count('shipments', fn ($q) => $q->where('status', 'DISPATCHED'))]],
            ['clause' => '8.7', 'title' => 'Pengendalian keluaran yang tidak sesuai', 'evidence' => 'Barang tidak sesuai otomatis masuk karantina dan membuka catatan ketidaksesuaian dengan disposisi.',
                'metrics' => ['terbuka' => $count('nonconformances', fn ($q) => $q->where('status', 'OPEN')), 'ditutup' => $count('nonconformances', fn ($q) => $q->where('status', 'CLOSED'))]],
            ['clause' => '9.1', 'title' => 'Pemantauan, pengukuran, analisis, dan evaluasi', 'evidence' => 'Analisis persediaan ABC/FSN/VED/HML/SDE/XYZ/EOQ dan cycle count dengan persetujuan selisih.',
                'metrics' => ['analisis' => $count('analysis_runs'), 'cycle_count_selesai' => $count('stocktakes', fn ($q) => $q->where('status', 'COMPLETED'))]],
            ['clause' => '10.2', 'title' => 'Ketidaksesuaian dan tindakan korektif', 'evidence' => 'CAPA dengan analisis akar masalah (5-Why/Fishbone), tindakan, dan verifikasi efektivitas.',
                'metrics' => ['capa_terbuka' => $count('capas', fn ($q) => $q->whereIn('status', ['OPEN', 'IN_PROGRESS'])), 'capa_terlambat' => $count('capas', fn ($q) => $q->whereIn('status', ['OPEN', 'IN_PROGRESS'])->where('due_date', '<', $today)), 'capa_ditutup' => $count('capas', fn ($q) => $q->where('status', 'CLOSED'))]],
        ];
    }
}
