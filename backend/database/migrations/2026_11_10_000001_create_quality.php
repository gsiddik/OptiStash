<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 (QA, CAPA, ISO 9001:2015 audit trail): CAPA with root-cause analysis, measuring-equipment calibration, controlled
 * documents (SOP) with versions, and database-level immutability of the audit trail and the stock movement ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nonconformances', function (Blueprint $t) {
            $t->string('title', 200)->nullable();
        });
        Schema::create('capas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('nonconformance_id')->nullable();
            $t->string('type', 10); // CORRECTIVE PREVENTIVE
            $t->string('title', 200);
            $t->string('description', 2000);
            $t->foreignId('owner_id')->constrained('users');
            $t->date('due_date');
            $t->string('status', 12)->default('OPEN'); // OPEN IN_PROGRESS VERIFIED CLOSED
            $t->string('rca_method', 10)->nullable(); // FIVE_WHY FISHBONE
            $t->json('rca')->nullable();
            $t->string('root_cause', 1000)->nullable();
            $t->string('verification_notes', 1000)->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status', 'due_date']);
            $t->foreign(['tenant_id', 'nonconformance_id'])->references(['tenant_id', 'id'])->on('nonconformances');
        });
        Schema::create('capa_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('capa_id');
            $t->string('description', 500);
            $t->foreignId('assignee_id')->constrained('users');
            $t->date('due_date');
            $t->timestampTz('done_at')->nullable();
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'capa_id'])->references(['tenant_id', 'id'])->on('capas');
        });
        Schema::create('instruments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->string('kind', 20); // THERMOMETER SCALE HYGROMETER OTHER
            $t->uuid('warehouse_id')->nullable();
            $t->unsignedSmallInteger('interval_days');
            $t->date('last_calibrated_on')->nullable();
            $t->date('next_due_on')->nullable();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('calibrations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('instrument_id');
            $t->date('calibrated_on');
            $t->string('performed_by', 150);
            $t->string('certificate_no', 80);
            $t->string('result', 4); // PASS FAIL
            $t->date('next_due_on')->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'instrument_id'])->references(['tenant_id', 'id'])->on('instruments');
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('title', 200);
            $t->string('category', 20); // SOP WORK_INSTRUCTION POLICY FORM
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('document_id');
            $t->unsignedSmallInteger('version_no');
            $t->string('status', 10)->default('DRAFT'); // DRAFT IN_REVIEW EFFECTIVE OBSOLETE
            $t->text('content');
            $t->char('checksum', 64);
            $t->string('change_summary', 500);
            $t->date('effective_on')->nullable();
            $t->foreignId('author_id')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['document_id', 'version_no']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents');
        });

        // The audit trail and the stock ledger are append-only: the database refuses any change or removal of a row.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION forbid_change();
            CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION forbid_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements; DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events; DROP FUNCTION IF EXISTS forbid_change();');
        foreach (['document_versions', 'documents', 'calibrations', 'instruments', 'capa_actions', 'capas'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('nonconformances', fn (Blueprint $t) => $t->dropColumn('title'));
    }
};
