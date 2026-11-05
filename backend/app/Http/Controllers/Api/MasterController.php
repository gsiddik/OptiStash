<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Master\MasterRegistry;
use App\Domain\Master\MasterService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Master data (warehouses, storage topology, products, packaging, partners). Reading needs
 * the internal access every operations screen needs (wms.access, checked by InternalAccess); writing needs master.manage.
 */
class MasterController extends Controller
{
    private function service(Request $r): MasterService
    {
        return new MasterService(self::storeFor($r));
    }

    private function writer(Request $r): MasterService
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');

        return $this->service($r);
    }

    public function schema()
    {
        return response()->json(['data' => MasterRegistry::schema()]);
    }

    public function index(Request $r, string $resource)
    {
        return response()->json($this->service($r)->list($resource, $r->query('q'), $r->query('active'), max(1, (int) $r->query('page', 1))));
    }

    public function options(Request $r, string $resource)
    {
        return response()->json(['data' => $this->service($r)->options($resource)]);
    }

    public function store(Request $r, string $resource)
    {
        return response()->json(['data' => $this->writer($r)->create($resource, $r->all())], 201);
    }

    public function update(Request $r, string $resource, string $id)
    {
        return response()->json(['data' => $this->writer($r)->update($resource, $id, $r->all())]);
    }

    public function status(Request $r, string $resource, string $id)
    {
        $d = $r->validate(['active' => 'required|boolean', 'reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->writer($r)->setActive($resource, $id, (bool) $d['active'], $d['reason'] ?? null)]);
    }
}
