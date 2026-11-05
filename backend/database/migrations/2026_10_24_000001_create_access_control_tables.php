<?php

use App\Domain\Access\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('group');
            $t->string('description');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name', 100);
            $t->string('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('permission_id')->constrained();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('membership_roles', function (Blueprint $t) {
            $t->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('role_id')->constrained();
            $t->primary(['membership_id', 'role_id']);
        });
        // The legacy `role` label stays for display and seeds the first role assignment once (SystemRoles::bootstrapMembership).
        Schema::table('memberships', function (Blueprint $t) {
            $t->boolean('roles_bootstrapped')->default(false);
        });

        // Existing tenants get their system roles and every membership keeps exactly the access of its former role.
        app(SystemRoles::class)->syncTenants();
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn('roles_bootstrapped'));
        foreach (['membership_roles', 'role_permissions', 'roles', 'permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
