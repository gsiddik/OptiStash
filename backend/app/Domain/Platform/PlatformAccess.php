<?php

namespace App\Domain\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform (Superadmin) permissions and system roles. Like tenant access, decisions check permissions, never role names;
 * `platform_administrator` and `platform_support` are only permission collections. Run `php artisan optistash:sync-access`.
 */
final class PlatformAccess
{
    public const PERMISSIONS = [
        'platform.tenant.view' => 'View tenants',
        'platform.tenant.manage' => 'Create tenants and activate or suspend them',
        'platform.tenant_admin.create' => 'Create the administrator of a tenant',
        'platform.admin.view' => 'View platform administrators',
        'platform.admin.manage' => 'Grant and revoke platform roles',
        'platform.audit.view' => 'View the platform audit log',
    ];

    public const SYSTEM_ROLES = [
        'platform_administrator' => '*',
        'platform_support' => ['platform.tenant.view', 'platform.admin.view', 'platform.audit.view'],
    ];

    public static function sync(): void
    {
        foreach (self::PERMISSIONS as $code => $description) {
            DB::table('platform_permissions')->where('code', $code)->exists()
                ? DB::table('platform_permissions')->where('code', $code)->update(['description' => $description])
                : DB::table('platform_permissions')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'description' => $description]);
        }
        $ids = DB::table('platform_permissions')->pluck('id', 'code');
        foreach (self::SYSTEM_ROLES as $name => $codes) {
            DB::table('platform_roles')->insertOrIgnore(['id' => (string) Str::uuid(), 'name' => $name, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            $roleId = DB::table('platform_roles')->where('name', $name)->value('id');
            $codes = $codes === '*' ? array_keys(self::PERMISSIONS) : $codes;
            DB::table('platform_role_permissions')->insertOrIgnore(array_map(fn ($c) => ['platform_role_id' => $roleId, 'platform_permission_id' => $ids[$c]], $codes));
        }
    }

    /** @return list<string> */
    public static function permissionsFor(int $userId): array
    {
        return DB::table('platform_role_assignments as a')
            ->join('platform_role_permissions as rp', 'rp.platform_role_id', '=', 'a.platform_role_id')
            ->join('platform_permissions as p', 'p.id', '=', 'rp.platform_permission_id')
            ->where('a.user_id', $userId)->distinct()->orderBy('p.code')->pluck('p.code')->all();
    }

    public static function authorize(Request $r, string $permission): void
    {
        abort_unless(in_array($permission, $r->attributes->get('platform_permissions', []), true), 403, 'Izin platform diperlukan.');
    }

    public static function audit(?int $actorId, string $action, string $subject, array $metadata = []): void
    {
        DB::table('platform_audit_events')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actorId, 'action' => $action, 'subject_id' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
