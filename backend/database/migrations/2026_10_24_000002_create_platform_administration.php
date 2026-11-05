<?php

use App\Domain\Platform\PlatformAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform (Superadmin) access is separate from tenant access: its own permissions, roles and assignments.
        Schema::create('platform_permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('description');
        });
        Schema::create('platform_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 100)->unique();
            $t->boolean('is_system')->default(false);
            $t->timestampsTz();
        });
        Schema::create('platform_role_permissions', function (Blueprint $t) {
            $t->foreignUuid('platform_role_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('platform_permission_id')->constrained();
            $t->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::create('platform_role_assignments', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('platform_role_id')->constrained();
            $t->timestampTz('created_at');
            $t->primary(['user_id', 'platform_role_id']);
        });
        Schema::create('platform_audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('subject_id');
            $t->json('metadata');
            $t->timestampTz('created_at');
            $t->index('created_at');
        });

        PlatformAccess::sync();
    }

    public function down(): void
    {
        foreach (['platform_audit_events', 'platform_role_assignments', 'platform_role_permissions', 'platform_roles', 'platform_permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
