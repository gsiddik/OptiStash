<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Master\MasterImporter;
use App\Domain\Master\MasterService;
use App\Domain\Tariff\TariffService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Excel/CSV import and export of the master data. Downloads need the internal access every screen needs; staging, committing and
 * discarding an import change data and need master.manage.
 */
class MasterImportController extends Controller
{
    private function importer(Request $r): MasterImporter
    {
        $store = self::storeFor($r);

        return new MasterImporter($store, new MasterService($store), new TariffService($store));
    }

    private function writer(Request $r, ?string $jobId = null): MasterImporter
    {
        $importer = $this->importer($r);
        $lines = $jobId !== null && $importer->job($jobId)['resource'] === 'rate_lines';
        $lines
            ? Access::authorize($r, 'rate.manage', 'Akun Anda tidak boleh mengubah tarif.')
            : Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');

        return $importer;
    }

    private static function format(Request $r): string
    {
        return $r->query('format') === 'csv' ? 'csv' : 'xlsx';
    }

    private function download(array $file)
    {
        return response()->download($file[0], $file[1])->deleteFileAfterSend(true);
    }

    public function template(Request $r, string $resource)
    {
        return $this->download($this->importer($r)->template($resource, self::format($r)));
    }

    public function export(Request $r, string $resource)
    {
        return $this->download($this->importer($r)->export($resource, self::format($r), $r->query('q'), $r->query('active')));
    }

    public function store(Request $r, string $resource)
    {
        $r->validate(['file' => 'required|file']);

        return response()->json(['data' => $this->writer($r)->preview($resource, $r->file('file'))], 201);
    }

    public function show(Request $r, string $id)
    {
        return response()->json(['data' => $this->writer($r, $id)->job($id)]);
    }

    public function rows(Request $r, string $id)
    {
        return response()->json($this->writer($r, $id)->rows($id, $r->query('action'), max(1, (int) $r->query('page', 1))));
    }

    public function errors(Request $r, string $id)
    {
        return $this->download($this->writer($r, $id)->rejected($id, self::format($r)));
    }

    public function commit(Request $r, string $id)
    {
        $d = $r->validate(['skip_errors' => 'nullable|boolean']);

        return response()->json(['data' => $this->writer($r, $id)->commit($id, (bool) ($d['skip_errors'] ?? false))]);
    }

    public function discard(Request $r, string $id)
    {
        return response()->json(['data' => $this->writer($r, $id)->discard($id)]);
    }
}
