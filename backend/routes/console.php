<?php

use App\Domain\Access\SystemRoles;
use App\Domain\Integration\Optinexus\OptinexusManifest;
use App\Domain\Platform\PlatformAccess;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('optistash:sync-access', function (SystemRoles $roles) {
    $roles->syncAll();
    $this->info('Permissions, system roles and first role assignments are in sync.');
})->purpose('Sync the permission catalog and each tenant\'s system roles');

Artisan::command('optistash:create-platform-admin {email} {--name=Platform Administrator}', function (string $email) {
    // Bootstraps the first Superadmin. The password is asked interactively (or read from OPTISTASH_PLATFORM_ADMIN_PASSWORD) and never seeded.
    PlatformAccess::sync();
    $password = getenv('OPTISTASH_PLATFORM_ADMIN_PASSWORD') ?: $this->secret('Password (min. 12, upper/lower case, number, symbol)');
    $validator = Validator::make(['email' => $email, 'password' => $password], ['email' => 'required|email|max:254', 'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
    if ($validator->fails()) {
        $this->error(implode(' ', $validator->errors()->all()));

        return 1;
    }
    $user = User::firstOrCreate(['email' => strtolower($email)], ['name' => $this->option('name'), 'password' => $password]);
    $role = DB::table('platform_roles')->where('name', 'platform_administrator')->value('id');
    DB::table('platform_role_assignments')->insertOrIgnore(['user_id' => $user->id, 'platform_role_id' => $role, 'created_at' => now()]);
    PlatformAccess::audit(null, 'platform.admin_bootstrapped', (string) $user->id, ['email' => $user->email]);
    $this->info("{$user->email} is a platform administrator.");

    return 0;
})->purpose('Create or promote the platform administrator (Superadmin)');

Artisan::command('optistash:nexus:manifest {--output= : Write the JSON to this file instead of printing it}', function (OptinexusManifest $manifest) {
    // What an OptiNexus operator imports and registers for OptiStash (docs/architecture/OPTINEXUS_ADAPTER.md §6). Contains no secret.
    $json = json_encode($manifest->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($path = $this->option('output')) {
        file_put_contents($path, $json.PHP_EOL);
        $this->info("Manifest written to {$path}.");

        return 0;
    }
    $this->line($json);

    return 0;
})->purpose('Print the OptiStash manifest v2 for OptiNexus');
