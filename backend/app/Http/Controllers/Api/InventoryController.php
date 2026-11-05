<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Inventory\InventoryAnalysis;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StocktakeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Inventory (Module 3): stock map and drill-down, locks, cycle counts with approval, analysis. Permissions are checked per action. */
class InventoryController extends Controller
{
    private function inv(Request $r): InventoryService
    {
        return new InventoryService(self::storeFor($r));
    }

    private function view(Request $r): void
    {
        Access::authorize($r, 'inventory.view', 'Akun Anda tidak boleh melihat persediaan.');
    }

    public function map(Request $r, string $warehouse)
    {
        $this->view($r);

        return response()->json(['data' => $this->inv($r)->map($warehouse)]);
    }

    public function unitsAt(Request $r, string $node)
    {
        $this->view($r);

        return response()->json(['data' => $this->inv($r)->unitsAt($node)]);
    }

    public function summary(Request $r)
    {
        $this->view($r);

        return response()->json(['data' => $this->inv($r)->summary($r->query('warehouse_id'))]);
    }

    public function lockUnit(Request $r, string $id)
    {
        Access::authorize($r, 'inventory.lock', 'Akun Anda tidak boleh mengunci stok.');
        $d = $r->validate(['locked' => 'required|boolean', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->inv($r)->setLocked($id, (bool) $d['locked'], $d['reason'])]);
    }

    public function lockLocation(Request $r, string $node)
    {
        Access::authorize($r, 'inventory.lock', 'Akun Anda tidak boleh mengunci stok.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => ['locked' => $this->inv($r)->lockLocation($node, $d['reason'])]]);
    }

    private function take(Request $r): StocktakeService
    {
        return new StocktakeService(self::storeFor($r));
    }

    public function stocktakes(Request $r)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh melihat penghitungan stok.');

        return response()->json(['data' => $this->take($r)->list($r->query('status'))]);
    }

    public function stocktake(Request $r, string $id)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh melihat penghitungan stok.');

        return response()->json(['data' => $this->take($r)->one($id)]);
    }

    public function startStocktake(Request $r)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh memulai penghitungan stok.');
        $d = $r->validate(['warehouse_id' => 'required|uuid', 'location_id' => 'nullable|uuid', 'notes' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->take($r)->start($d['warehouse_id'], $d['location_id'] ?? null, $d['notes'] ?? null)], 201);
    }

    public function count(Request $r, string $line)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh menghitung stok.');
        $d = $r->validate(['counted_qty' => 'required|integer|min:0|max:100000000', 'reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->take($r)->count($line, $d['counted_qty'], $d['reason'] ?? null)]);
    }

    public function submitStocktake(Request $r, string $id)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh mengajukan penghitungan stok.');

        return response()->json(['data' => $this->take($r)->submit($id)]);
    }

    public function cancelStocktake(Request $r, string $id)
    {
        Access::authorize($r, 'stocktake.manage', 'Akun Anda tidak boleh membatalkan penghitungan stok.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->take($r)->cancel($id, $d['reason'])]);
    }

    public function approveStocktake(Request $r, string $id)
    {
        Access::authorize($r, 'stocktake.approve', 'Akun Anda tidak boleh menyetujui penyesuaian stok.');

        return response()->json(['data' => $this->take($r)->approve($id)]);
    }

    public function rejectStocktake(Request $r, string $id)
    {
        Access::authorize($r, 'stocktake.approve', 'Akun Anda tidak boleh menolak penghitungan stok.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->take($r)->reject($id, $d['reason'])]);
    }

    private function analysis(Request $r, string $permission): InventoryAnalysis
    {
        Access::authorize($r, $permission, 'Akun Anda tidak boleh mengakses analisis persediaan.');

        return new InventoryAnalysis(self::storeFor($r));
    }

    public function analysisConfig(Request $r)
    {
        return response()->json(['data' => $this->analysis($r, 'inventory.analysis.view')->config()]);
    }

    public function saveAnalysisConfig(Request $r)
    {
        $service = $this->analysis($r, 'inventory.analysis.configure');
        $d = $r->validate(array_fill_keys(array_keys(InventoryAnalysis::DEFAULTS), 'sometimes|numeric'));

        return response()->json(['data' => $service->saveConfig($d)]);
    }

    public function runAnalysis(Request $r)
    {
        return response()->json(['data' => $this->analysis($r, 'inventory.analysis.configure')->run()], 201);
    }

    public function analysisRuns(Request $r)
    {
        return response()->json(['data' => $this->analysis($r, 'inventory.analysis.view')->runs()]);
    }

    public function analysisResults(Request $r)
    {
        return response()->json($this->analysis($r, 'inventory.analysis.view')->results($r->query('run_id'), $r->only(InventoryAnalysis::DIMENSIONS)));
    }

    public function analysisMatrix(Request $r)
    {
        $d = $r->validate(['row' => 'required|string', 'col' => 'required|string', 'run_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->analysis($r, 'inventory.analysis.view')->matrix($d['run_id'] ?? null, $d['row'], $d['col'])]);
    }
}
