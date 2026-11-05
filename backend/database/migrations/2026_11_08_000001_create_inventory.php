<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 3 (Inventory): stock locks, cycle counts with discrepancy approval, and the inventory analysis snapshots
 * (ABC, FSN, VED, HML, SDE, XYZ, EOQ). IoT tag binding and telemetry alerts arrive with Module 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('unit_cost')->nullable(); // whole rupiah per base unit (ABC value, HML class, EOQ holding cost)
            $t->string('criticality', 1)->default('D'); // VED: V vital, E essential, D desirable
            $t->unsignedSmallInteger('lead_time_days')->nullable(); // supply lead time (SDE)
        });
        Schema::table('stock_units', function (Blueprint $t) {
            $t->boolean('locked')->default(false);
            $t->string('lock_reason', 500)->nullable();
            $t->foreignId('locked_by')->nullable()->constrained('users');
            $t->timestampTz('locked_at')->nullable();
        });
        Schema::create('stocktakes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('warehouse_id');
            $t->uuid('location_id')->nullable(); // null = the whole warehouse; otherwise this location and everything below it
            $t->string('status', 16)->default('COUNTING'); // COUNTING PENDING_APPROVAL COMPLETED CANCELLED
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('submitted_by')->nullable()->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('stocktake_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('stocktake_id');
            $t->uuid('stock_unit_id');
            $t->unsignedInteger('expected_qty'); // frozen when the count starts
            $t->unsignedInteger('counted_qty')->nullable();
            $t->string('variance_reason', 500)->nullable();
            $t->foreignId('counted_by')->nullable()->constrained('users');
            $t->timestampTz('counted_at')->nullable();
            $t->unique(['stocktake_id', 'stock_unit_id']);
            $t->index(['tenant_id', 'stock_unit_id']);
            $t->foreign(['tenant_id', 'stocktake_id'])->references(['tenant_id', 'id'])->on('stocktakes');
            $t->foreign(['tenant_id', 'stock_unit_id'])->references(['tenant_id', 'id'])->on('stock_units');
        });
        Schema::create('analysis_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->timestampTz('run_at');
            $t->unsignedSmallInteger('window_days');
            $t->json('config');
            $t->unsignedInteger('products');
            $t->foreignId('created_by')->constrained('users');
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'run_at']);
        });
        Schema::create('analysis_results', function (Blueprint $t) {
            $t->uuid('run_id');
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('product_id');
            $t->unsignedBigInteger('consumption_qty');
            $t->unsignedBigInteger('consumption_value');
            $t->unsignedInteger('movements');
            $t->unsignedInteger('days_since_last_issue')->nullable();
            $t->string('abc', 1);
            $t->string('fsn', 1);
            $t->string('ved', 1);
            $t->string('hml', 1);
            $t->string('sde', 1);
            $t->string('xyz', 1);
            $t->unsignedInteger('eoq')->nullable();
            $t->primary(['run_id', 'product_id']);
            $t->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('analysis_runs');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
    }

    public function down(): void
    {
        foreach (['analysis_results', 'analysis_runs', 'stocktake_lines', 'stocktakes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('stock_units', fn (Blueprint $t) => $t->dropColumn(['locked', 'lock_reason', 'locked_by', 'locked_at']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['unit_cost', 'criticality', 'lead_time_days']));
    }
};
