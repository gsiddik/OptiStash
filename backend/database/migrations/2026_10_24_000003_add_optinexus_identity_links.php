<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links for the OptiNexus identity mode (docs/architecture/OPTINEXUS_ADAPTER.md). All additive and nullable, so the local
 * identity mode is unchanged. `*_deactivated_at` marks a deactivation that OptiNexus asked for, so the next successful
 * sign-in undoes exactly that; `session_epoch` is stored in each session at sign-in; raising it ends every session of the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->uuid('optinexus_tenant_id')->nullable()->unique();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('optinexus_subject', 64)->nullable()->unique();
            $t->boolean('active')->default(true);
            $t->timestampTz('optinexus_deactivated_at')->nullable();
            $t->unsignedInteger('session_epoch')->default(0);
        });
        Schema::table('memberships', function (Blueprint $t) {
            $t->timestampTz('optinexus_deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn('optinexus_deactivated_at'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['optinexus_subject']);
            $t->dropColumn(['optinexus_subject', 'active', 'optinexus_deactivated_at', 'session_epoch']);
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropUnique(['optinexus_tenant_id']);
            $t->dropColumn('optinexus_tenant_id');
        });
    }
};
