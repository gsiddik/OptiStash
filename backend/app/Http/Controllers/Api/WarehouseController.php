<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Warehouse\StorageTopology;
use App\Domain\Warehouse\SupplierPortalUsers;
use App\Domain\Warehouse\TransportSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Storage hierarchy, supplier portal accounts and the transport integration setting (Module 1). Permissions are checked per action. */
class WarehouseController extends Controller
{
    private function topology(Request $r): StorageTopology
    {
        return new StorageTopology(self::storeFor($r));
    }

    public function tree(Request $r, string $warehouse)
    {
        Access::authorize($r, 'master.view', 'Akun Anda tidak boleh melihat struktur gudang.');

        return response()->json(['data' => $this->topology($r)->tree($warehouse)]);
    }

    public function createNode(Request $r, string $warehouse)
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');
        $d = $r->validate([
            'parent_id' => 'nullable|uuid', 'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'name' => 'required|string|max:200', 'capacity_base_units' => 'nullable|integer|min:1|max:100000000',
        ]);

        return response()->json(['data' => $this->topology($r)->create($warehouse, $d['parent_id'] ?? null, $d['code'], $d['name'], $d['capacity_base_units'] ?? null)], 201);
    }

    public function generateNodes(Request $r, string $warehouse)
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');
        $d = $r->validate([
            'parent_id' => 'nullable|uuid', 'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'count' => 'required|integer|min:1|max:'.StorageTopology::MAX_GENERATED, 'start' => 'nullable|integer|min:0|max:100000',
            'width' => 'nullable|integer|min:1|max:6', 'capacity_base_units' => 'nullable|integer|min:1|max:100000000',
        ]);
        $nodes = $this->topology($r)->generate($warehouse, $d['parent_id'] ?? null, $d['prefix'], $d['count'], $d['start'] ?? 1, $d['width'] ?? 2, $d['capacity_base_units'] ?? null);

        return response()->json(['data' => ['created' => count($nodes), 'first' => $nodes[0]->code, 'last' => end($nodes)->code]], 201);
    }

    public function updateNode(Request $r, string $node)
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');
        $d = $r->validate(['name' => 'required|string|max:200', 'capacity_base_units' => 'nullable|integer|min:1|max:100000000']);

        return response()->json(['data' => $this->topology($r)->update($node, $d['name'], $d['capacity_base_units'] ?? null)]);
    }

    public function blockNode(Request $r, string $node)
    {
        Access::authorize($r, 'location.block', 'Akun Anda tidak boleh memblokir lokasi.');
        $d = $r->validate(['blocked' => 'required|boolean', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->topology($r)->setBlocked($node, (bool) $d['blocked'], $d['reason'])]);
    }

    public function deactivateNode(Request $r, string $node)
    {
        Access::authorize($r, 'master.manage', 'Akun Anda tidak boleh mengubah master data.');
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->topology($r)->deactivate($node, $d['reason'])]);
    }

    public function transport(Request $r)
    {
        Access::authorize($r, 'integration.view', 'Akun Anda tidak boleh melihat integrasi.');

        return response()->json(['data' => TransportSettings::get($r->attributes->get('tenant')->id)]);
    }

    public function setTransport(Request $r)
    {
        Access::authorize($r, 'integration.manage', 'Akun Anda tidak boleh mengubah integrasi.');
        $d = $r->validate(['mode' => 'required|string|in:'.implode(',', TransportSettings::MODES), 'api_url' => 'nullable|string|max:300']);

        return response()->json(['data' => TransportSettings::put(self::storeFor($r), $d['mode'], $d['api_url'] ?? null)]);
    }

    public function portalUsers(Request $r, string $partner)
    {
        Access::authorize($r, 'partner.provision', 'Akun Anda tidak boleh mengelola akun portal pemasok.');

        return response()->json(['data' => (new SupplierPortalUsers(self::storeFor($r)))->list($partner)]);
    }

    public function createPortalUser(Request $r, string $partner)
    {
        Access::authorize($r, 'partner.provision', 'Akun Anda tidak boleh mengelola akun portal pemasok.');
        $d = $r->validate([
            'name' => 'required|string|max:150', 'email' => 'required|email|max:254',
            'password' => ['required', 'string', 'max:1024', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        return response()->json(['data' => (new SupplierPortalUsers(self::storeFor($r)))->create($partner, $d['name'], $d['email'], $d['password'])], 201);
    }

    public function setPortalUserActive(Request $r, string $partner, int $membership)
    {
        Access::authorize($r, 'partner.provision', 'Akun Anda tidak boleh mengelola akun portal pemasok.');
        $d = $r->validate(['active' => 'required|boolean']);
        (new SupplierPortalUsers(self::storeFor($r)))->setActive($partner, $membership, (bool) $d['active']);

        return response()->noContent();
    }
}
