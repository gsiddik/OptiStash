<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging for spreadsheet imports (phase F0, module M12). An uploaded file is read into rows, every row is
 * validated and given the action it would cause, and nothing touches the real tables until someone reviews the preview and
 * commits the job. Generic on purpose: masters use it now; tariffs, SPM and opening balances reuse it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('resource', 60);
            $t->string('filename', 255);
            $t->string('format', 8);
            $t->char('file_hash', 64);
            $t->string('status', 12)->default('PREVIEW');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('rows_new')->default(0);
            $t->unsignedInteger('rows_update')->default(0);
            $t->unsignedInteger('rows_unchanged')->default(0);
            $t->unsignedInteger('rows_error')->default(0);
            $t->json('headers');
            $t->json('result')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('committed_by')->nullable()->constrained('users');
            $t->timestampTz('committed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'resource', 'file_hash']);
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('job_id');
            $t->unsignedInteger('row_number');
            $t->string('action', 10);
            $t->uuid('target_id')->nullable();
            $t->json('raw');
            $t->json('data')->nullable();
            $t->json('errors')->nullable();
            $t->foreign(['tenant_id', 'job_id'])->references(['tenant_id', 'id'])->on('import_jobs')->cascadeOnDelete();
            $t->unique(['job_id', 'row_number']);
            $t->index(['job_id', 'action']);
        });
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status_check CHECK (status IN ('PREVIEW','COMMITTED','DISCARDED'))");
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_action_check CHECK (action IN ('CREATE','UPDATE','NONE','ERROR'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
