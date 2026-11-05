<?php

namespace App\Domain\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tenant permission checks. TenantContext resolves the member's permissions once per request; everything else asks here. */
final class Access
{
    /** @return list<string> */
    public static function permissionsFor(int $membershipId): array
    {
        return DB::table('membership_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.membership_id', $membershipId)
            ->distinct()->orderBy('p.code')->pluck('p.code')->all();
    }

    public static function can(Request $r, string $permission): bool
    {
        return in_array($permission, $r->attributes->get('permissions', []), true);
    }

    public static function authorize(Request $r, string $permission, string $message = 'Akses ditolak.'): void
    {
        abort_unless(self::can($r, $permission), 403, $message);
    }

    /** True for a membership bound to a supplier (a partner of type SUPPLIER): its data is limited to that supplier. */
    public static function isSupplier(Request $r): bool
    {
        return $r->attributes->get('supplier_id') !== null;
    }
}
