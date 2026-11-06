<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Local trial data: the tenant `demo` with one user per internal role. Refuses anything but local/testing and never resets a password. */
class DemoSeeder extends Seeder
{
    public const ROLES = ['tenant_administrator', 'warehouse_manager', 'inbound_operator', 'outbound_operator', 'qc_inspector', 'quality_auditor', 'viewer'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seed is local/testing only.');
        }
        $password = getenv('OPTISTASH_DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Set OPTISTASH_DEMO_PASSWORD (at least 12 characters), never a production credential.');
        }
        DB::transaction(function () use ($password) {
            $tenant = DB::table('tenants')->where('slug', 'demo')->first();
            $id = $tenant?->id ?? (string) Str::uuid();
            if (! $tenant) {
                DB::table('tenants')->insert(['id' => $id, 'slug' => 'demo', 'name' => 'OptiStash Demo', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (self::ROLES as $role) {
                $user = User::firstOrCreate(['email' => $role.'@optistash.test'], ['name' => ucwords(str_replace('_', ' ', $role)), 'password' => Hash::make($password)]);
                DB::table('memberships')->updateOrInsert(['tenant_id' => $id, 'user_id' => $user->id], ['role' => $role, 'active' => true]);
            }
        });
    }
}
