<?php

namespace App\Domain\Quality;

use App\Domain\Shared\TenantStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Controlled documents (SOP, work instructions, policies, forms; ISO 9001:2015 clause 7.5). A version is DRAFT while it is written,
 * IN_REVIEW once submitted, EFFECTIVE when someone other than its author approves it (the previous effective version becomes OBSOLETE)
 * and never changes afterwards: the checksum recorded at submission is verified at approval, and content cannot be edited after submit.
 */
final class DocumentService
{
    public const CATEGORIES = ['SOP', 'WORK_INSTRUCTION', 'POLICY', 'FORM'];

    public function __construct(private readonly TenantStore $store) {}

    public function create(string $code, string $title, string $category, string $content, string $summary): object
    {
        abort_unless(in_array($category, self::CATEGORIES, true), 422, 'Kategori dokumen tidak dikenal.');

        return DB::transaction(function () use ($code, $title, $category, $content, $summary) {
            $code = Str::upper(trim($code));
            abort_if($this->store->query('documents')->where('code', $code)->exists(), 422, "Kode dokumen {$code} sudah dipakai.");
            $id = (string) Str::uuid();
            DB::table('documents')->insert(['id' => $id, 'tenant_id' => $this->store->tenantId, 'code' => $code, 'title' => $title, 'category' => $category, 'created_at' => now(), 'updated_at' => now()]);
            $this->insertVersion($id, 1, $content, $summary);
            $this->store->event('document.created', $id, ['code' => $code]);

            return $this->one($id);
        }, 3);
    }

    private function insertVersion(string $docId, int $no, string $content, string $summary): void
    {
        DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->store->tenantId, 'document_id' => $docId, 'version_no' => $no, 'status' => 'DRAFT', 'content' => $content,
            'checksum' => hash('sha256', $content), 'change_summary' => $summary, 'author_id' => $this->store->actorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A new draft version; only one unfinished version can exist at a time. */
    public function newVersion(string $docId, string $content, string $summary): object
    {
        return DB::transaction(function () use ($docId, $content, $summary) {
            $d = $this->store->find('documents', $docId, true);
            abort_unless($d->active, 422, 'Dokumen tidak aktif.');
            abort_if($this->store->query('document_versions')->where('document_id', $docId)->whereIn('status', ['DRAFT', 'IN_REVIEW'])->exists(), 422, 'Masih ada versi draf atau dalam review.');
            $no = (int) $this->store->query('document_versions')->where('document_id', $docId)->max('version_no') + 1;
            $this->insertVersion($docId, $no, $content, $summary);
            $this->store->event('document.version_created', $docId, ['code' => $d->code, 'version' => $no]);

            return $this->one($docId);
        }, 3);
    }

    public function editDraft(string $versionId, string $content, string $summary): object
    {
        return DB::transaction(function () use ($versionId, $content, $summary) {
            $v = $this->store->find('document_versions', $versionId, true);
            abort_unless($v->status === 'DRAFT', 422, 'Hanya versi draf yang dapat diubah.');
            abort_unless($v->author_id === $this->store->actorId, 403, 'Hanya penulis yang dapat mengubah drafnya.');
            DB::table('document_versions')->where('tenant_id', $this->store->tenantId)->where('id', $versionId)->update(['content' => $content, 'checksum' => hash('sha256', $content), 'change_summary' => $summary, 'updated_at' => now()]);

            return $this->one($v->document_id);
        }, 3);
    }

    public function submit(string $versionId): object
    {
        return DB::transaction(function () use ($versionId) {
            $v = $this->store->find('document_versions', $versionId, true);
            abort_unless($v->status === 'DRAFT', 422, 'Hanya versi draf yang dapat diajukan.');
            abort_unless($v->author_id === $this->store->actorId, 403, 'Hanya penulis yang dapat mengajukan drafnya.');
            DB::table('document_versions')->where('tenant_id', $this->store->tenantId)->where('id', $versionId)->update(['status' => 'IN_REVIEW', 'checksum' => hash('sha256', $v->content), 'updated_at' => now()]);
            $this->store->event('document.submitted', $v->document_id, ['version' => $v->version_no]);

            return $this->one($v->document_id);
        }, 3);
    }

    public function approve(string $versionId, ?string $effectiveOn): object
    {
        abort_if($effectiveOn !== null && $effectiveOn < now()->toDateString(), 422, 'Tanggal berlaku tidak boleh di masa lalu.');

        return DB::transaction(function () use ($versionId, $effectiveOn) {
            $v = $this->store->find('document_versions', $versionId, true);
            abort_unless($v->status === 'IN_REVIEW', 422, 'Hanya versi yang dalam review yang dapat disetujui.');
            abort_if($v->author_id === $this->store->actorId, 403, 'Penulis tidak dapat menyetujui versinya sendiri.');
            abort_unless(hash_equals($v->checksum, hash('sha256', $v->content)), 409, 'Isi dokumen tidak cocok dengan checksum saat diajukan.');
            DB::table('document_versions')->where('tenant_id', $this->store->tenantId)->where('document_id', $v->document_id)->where('status', 'EFFECTIVE')->update(['status' => 'OBSOLETE', 'updated_at' => now()]);
            DB::table('document_versions')->where('tenant_id', $this->store->tenantId)->where('id', $versionId)->update(['status' => 'EFFECTIVE', 'approved_by' => $this->store->actorId, 'approved_at' => now(), 'effective_on' => $effectiveOn ?? now()->toDateString(), 'updated_at' => now()]);
            $this->store->event('document.approved', $v->document_id, ['version' => $v->version_no]);

            return $this->one($v->document_id);
        }, 3);
    }

    /** Sends a version in review back to its author. */
    public function returnToDraft(string $versionId, string $reason): object
    {
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Alasan minimal 5 karakter.');

        return DB::transaction(function () use ($versionId, $reason) {
            $v = $this->store->find('document_versions', $versionId, true);
            abort_unless($v->status === 'IN_REVIEW', 422, 'Hanya versi yang dalam review yang dapat dikembalikan.');
            DB::table('document_versions')->where('tenant_id', $this->store->tenantId)->where('id', $versionId)->update(['status' => 'DRAFT', 'updated_at' => now()]);
            $this->store->event('document.returned', $v->document_id, ['version' => $v->version_no, 'reason' => trim($reason)]);

            return $this->one($v->document_id);
        }, 3);
    }

    public function one(string $id): object
    {
        $d = $this->store->find('documents', $id);
        $d->versions = DB::table('document_versions as v')->where('v.tenant_id', $this->store->tenantId)->where('v.document_id', $id)->join('users as a', 'a.id', '=', 'v.author_id')
            ->leftJoin('users as p', 'p.id', '=', 'v.approved_by')->orderByDesc('v.version_no')
            ->get(['v.id', 'v.version_no', 'v.status', 'v.content', 'v.checksum', 'v.change_summary', 'v.effective_on', 'v.author_id', 'a.name as author_name', 'p.name as approver_name', 'v.approved_at'])->all();

        return $d;
    }

    /** The register: every document with its effective version number. */
    public function list(): array
    {
        return DB::table('documents as d')->where('d.tenant_id', $this->store->tenantId)
            ->leftJoin('document_versions as v', fn ($j) => $j->on('v.document_id', '=', 'd.id')->on('v.tenant_id', '=', 'd.tenant_id')->where('v.status', 'EFFECTIVE'))
            ->orderBy('d.code')->get(['d.id', 'd.code', 'd.title', 'd.category', 'd.active', 'v.version_no as effective_version', 'v.effective_on'])->all();
    }
}
