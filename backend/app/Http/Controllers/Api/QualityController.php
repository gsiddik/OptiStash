<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Quality\AuditTrail;
use App\Domain\Quality\CalibrationService;
use App\Domain\Quality\CapaService;
use App\Domain\Quality\DocumentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Quality (Module 5): non-conformances, CAPA, calibration, controlled documents, audit trail and the ISO clause map. */
class QualityController extends Controller
{
    private function need(Request $r, string $permission, string $message = 'Akses ditolak.'): void
    {
        Access::authorize($r, $permission, $message);
    }

    private function capa(Request $r): CapaService
    {
        return new CapaService(self::storeFor($r));
    }

    public function people(Request $r)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => DB::table('memberships as m')->where('m.tenant_id', $r->attributes->get('tenant')->id)->where('m.active', true)->whereNull('m.supplier_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')->orderBy('u.name')->get(['u.id', 'u.name'])]);
    }

    public function raiseNc(Request $r)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh mencatat ketidaksesuaian.');
        $d = $r->validate(['source' => 'required|string|max:12', 'category' => 'required|string|max:20', 'title' => 'required|string|max:200', 'description' => 'required|string|min:5|max:1000']);

        return response()->json(['data' => $this->capa($r)->raiseNonconformance($d['source'], $d['category'], $d['title'], $d['description'])], 201);
    }

    public function capas(Request $r)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->capa($r)->list($r->query('status'))]);
    }

    public function capaShow(Request $r, string $id)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->capa($r)->one($id)]);
    }

    public function createCapa(Request $r)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh membuat CAPA.');
        $d = $r->validate(['nonconformance_id' => 'nullable|uuid', 'type' => 'required|string|in:CORRECTIVE,PREVENTIVE', 'title' => 'required|string|max:200', 'description' => 'required|string|max:2000', 'owner_id' => 'required|integer', 'due_date' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => $this->capa($r)->createCapa($d)], 201);
    }

    public function analyse(Request $r, string $id)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh menganalisis CAPA.');
        $d = $r->validate(['method' => 'required|string|in:FIVE_WHY,FISHBONE', 'rca' => 'required|array', 'root_cause' => 'required|string|max:1000']);

        return response()->json(['data' => $this->capa($r)->analyse($id, $d['method'], $d['rca'], $d['root_cause'])]);
    }

    public function addAction(Request $r, string $id)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh menambah tindakan CAPA.');
        $d = $r->validate(['description' => 'required|string|max:500', 'assignee_id' => 'required|integer', 'due_date' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => $this->capa($r)->addAction($id, $d['description'], $d['assignee_id'], $d['due_date'])], 201);
    }

    public function completeAction(Request $r, string $action)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->capa($r)->completeAction($action)]);
    }

    public function verify(Request $r, string $id)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh memverifikasi CAPA.');
        $d = $r->validate(['notes' => 'required|string|max:1000']);

        return response()->json(['data' => $this->capa($r)->verify($id, $d['notes'])]);
    }

    public function closeCapa(Request $r, string $id)
    {
        $this->need($r, 'capa.manage', 'Akun Anda tidak boleh menutup CAPA.');

        return response()->json(['data' => $this->capa($r)->close($id)]);
    }

    private function cal(Request $r): CalibrationService
    {
        return new CalibrationService(self::storeFor($r));
    }

    public function instruments(Request $r)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->cal($r)->list()]);
    }

    public function instrument(Request $r, string $id)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->cal($r)->one($id)]);
    }

    public function registerInstrument(Request $r)
    {
        $this->need($r, 'calibration.manage', 'Akun Anda tidak boleh mengelola alat ukur.');
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'], 'name' => 'required|string|max:200', 'kind' => 'required|string|max:20', 'warehouse_id' => 'nullable|uuid', 'interval_days' => 'required|integer|min:1|max:1825']);

        return response()->json(['data' => $this->cal($r)->register($d['code'], $d['name'], $d['kind'], $d['warehouse_id'] ?? null, $d['interval_days'])], 201);
    }

    public function calibrate(Request $r, string $id)
    {
        $this->need($r, 'calibration.manage', 'Akun Anda tidak boleh mencatat kalibrasi.');
        $d = $r->validate(['calibrated_on' => 'required|date_format:Y-m-d', 'performed_by' => 'required|string|max:150', 'certificate_no' => 'required|string|max:80', 'result' => 'required|string|in:PASS,FAIL', 'next_due_on' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->cal($r)->record($id, $d['calibrated_on'], $d['performed_by'], $d['certificate_no'], $d['result'], $d['next_due_on'] ?? null, $d['notes'] ?? null)], 201);
    }

    public function deactivateInstrument(Request $r, string $id)
    {
        $this->need($r, 'calibration.manage', 'Akun Anda tidak boleh mengelola alat ukur.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->cal($r)->deactivate($id, $d['reason'])]);
    }

    private function docs(Request $r): DocumentService
    {
        return new DocumentService(self::storeFor($r));
    }

    public function documents(Request $r)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->docs($r)->list()]);
    }

    public function document(Request $r, string $id)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => $this->docs($r)->one($id)]);
    }

    public function createDocument(Request $r)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh mengelola dokumen terkendali.');
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'], 'title' => 'required|string|max:200', 'category' => 'required|string|max:20', 'content' => 'required|string|max:200000', 'change_summary' => 'required|string|max:500']);

        return response()->json(['data' => $this->docs($r)->create($d['code'], $d['title'], $d['category'], $d['content'], $d['change_summary'])], 201);
    }

    public function newVersion(Request $r, string $id)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh mengelola dokumen terkendali.');
        $d = $r->validate(['content' => 'required|string|max:200000', 'change_summary' => 'required|string|max:500']);

        return response()->json(['data' => $this->docs($r)->newVersion($id, $d['content'], $d['change_summary'])], 201);
    }

    public function editVersion(Request $r, string $version)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh mengelola dokumen terkendali.');
        $d = $r->validate(['content' => 'required|string|max:200000', 'change_summary' => 'required|string|max:500']);

        return response()->json(['data' => $this->docs($r)->editDraft($version, $d['content'], $d['change_summary'])]);
    }

    public function submitVersion(Request $r, string $version)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh mengelola dokumen terkendali.');

        return response()->json(['data' => $this->docs($r)->submit($version)]);
    }

    public function approveVersion(Request $r, string $version)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh menyetujui dokumen.');
        $d = $r->validate(['effective_on' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->docs($r)->approve($version, $d['effective_on'] ?? null)]);
    }

    public function returnVersion(Request $r, string $version)
    {
        $this->need($r, 'sop.manage', 'Akun Anda tidak boleh menyetujui dokumen.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->docs($r)->returnToDraft($version, $d['reason'])]);
    }

    private const AUDIT_FILTERS = ['action', 'actor_id', 'subject_id', 'from', 'to'];

    private function auditFilters(Request $r): array
    {
        return $r->validate(['action' => 'nullable|string|max:80', 'actor_id' => 'nullable|integer', 'subject_id' => 'nullable|uuid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
    }

    public function audit(Request $r)
    {
        $this->need($r, 'audit.view', 'Akun Anda tidak boleh melihat jejak audit.');

        return response()->json((new AuditTrail(self::storeFor($r)))->search($this->auditFilters($r), max(1, (int) $r->query('page', 1))));
    }

    public function auditExport(Request $r)
    {
        $this->need($r, 'audit.view', 'Akun Anda tidak boleh melihat jejak audit.');
        $store = self::storeFor($r);
        $rows = (new AuditTrail($store))->export($this->auditFilters($r));
        $store->event('audit.exported', $store->tenantId, ['filters' => array_filter($r->only(self::AUDIT_FILTERS))]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'jejak-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function isoMap(Request $r)
    {
        $this->need($r, 'quality.view');

        return response()->json(['data' => (new AuditTrail(self::storeFor($r)))->isoMap()]);
    }
}
