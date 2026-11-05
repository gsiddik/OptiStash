<?php

namespace App\Http\Controllers\Api;

use App\Domain\Access\Access;
use App\Domain\Access\RoleService;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessController extends Controller
{
    public function permissions(Request $r)
    {
        Access::authorize($r, 'access.role.view');

        return response()->json(['data' => DB::table('permissions')->orderBy('group')->orderBy('code')->get(['code', 'group', 'description'])]);
    }

    public function roles(Request $r, RoleService $service)
    {
        Access::authorize($r, 'access.role.view');

        return response()->json(['data' => $service->roles(self::storeFor($r))]);
    }

    public function createRole(Request $r, RoleService $service)
    {
        Access::authorize($r, 'access.role.manage');
        self::localAuthority();

        return response()->json(['data' => $service->create(self::storeFor($r), $this->roleData($r), $r->attributes->get('permissions'))], 201);
    }

    public function updateRole(Request $r, string $id, RoleService $service)
    {
        Access::authorize($r, 'access.role.manage');
        self::localAuthority();

        return response()->json(['data' => $service->update(self::storeFor($r), $id, $this->roleData($r), $r->attributes->get('permissions'))]);
    }

    public function deleteRole(Request $r, string $id, RoleService $service)
    {
        Access::authorize($r, 'access.role.manage');
        self::localAuthority();
        $service->delete(self::storeFor($r), $id);

        return response()->noContent();
    }

    public function members(Request $r)
    {
        Access::authorize($r, 'access.user.manage');
        $s = self::storeFor($r);
        $roles = DB::table('membership_roles')->join('roles', 'roles.id', '=', 'membership_roles.role_id')
            ->where('roles.tenant_id', $s->tenantId)->get(['membership_roles.membership_id', 'roles.id', 'roles.name'])->groupBy('membership_id');

        return response()->json(['data' => DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.tenant_id', $s->tenantId)->orderBy('users.name')
            ->get(['memberships.id', 'memberships.user_id', 'users.name', 'users.email', 'memberships.active', 'memberships.vendor_id', 'memberships.customer_id'])
            ->map(function ($m) use ($roles) {
                $m->roles = ($roles[$m->id] ?? collect())->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values();

                return $m;
            })]);
    }

    public function assign(Request $r, int $membership, RoleService $service)
    {
        Access::authorize($r, 'access.user.manage');
        self::localAuthority();
        $d = $r->validate(['role_ids' => 'required|array|min:1|max:20', 'role_ids.*' => 'required|uuid|distinct']);
        $service->assign(self::storeFor($r), $membership, $d['role_ids'], $r->attributes->get('permissions'));

        return response()->json(['data' => ['status' => 'updated']]);
    }

    private function roleData(Request $r): array
    {
        return $r->validate([
            'name' => 'required|string|min:3|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'required|array|min:1|max:100',
            'permissions.*' => 'required|string|max:100|distinct',
        ]);
    }

    /** In OptiNexus mode roles and assignments live in OptiNexus; the local tables are not a second authority. */
    private static function localAuthority(): void
    {
        abort_if(OptinexusSettings::active(), 409, 'Role dan anggota dikelola di OptiNexus.');
    }
}
