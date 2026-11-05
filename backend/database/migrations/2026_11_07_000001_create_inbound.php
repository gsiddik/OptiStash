<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 (Inbound & QC): docks, ASN, dock bookings, receipts, stock units (one per LPN, the on-hand record Module 3 builds on),
 * the immutable stock movement ledger, putaway tasks and the non-conformance record of ISO 9001:2015 clause 8.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->uuid('warehouse_id');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('asns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('supplier_id');
            $t->uuid('warehouse_id');
            $t->string('status', 12)->default('DRAFT'); // DRAFT SUBMITTED CONFIRMED ARRIVED RECEIVING RECEIVED CANCELLED
            $t->string('source', 8)->default('INTERNAL'); // INTERNAL | PORTAL
            $t->date('expected_date');
            $t->string('reference', 120)->nullable(); // supplier delivery note / PO number
            $t->string('truck_plate', 30)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status', 'expected_date']);
            $t->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('partners');
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('asn_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('asn_id');
            $t->unsignedSmallInteger('line_no');
            $t->uuid('product_id');
            $t->unsignedInteger('expected_qty'); // in the product's base unit
            $t->unsignedInteger('received_qty')->default(0);
            $t->unique(['asn_id', 'line_no']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'asn_id'])->references(['tenant_id', 'id'])->on('asns');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('dock_bookings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('dock_id');
            $t->uuid('asn_id')->nullable();
            $t->timestampTz('slot_start');
            $t->timestampTz('slot_end');
            $t->string('status', 10)->default('BOOKED'); // BOOKED GATE_IN GATE_OUT CANCELLED
            $t->string('truck_plate', 30)->nullable();
            $t->timestampTz('gate_in_at')->nullable();
            $t->timestampTz('gate_out_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'dock_id', 'slot_start']);
            $t->foreign(['tenant_id', 'dock_id'])->references(['tenant_id', 'id'])->on('docks');
            $t->foreign(['tenant_id', 'asn_id'])->references(['tenant_id', 'id'])->on('asns');
        });
        Schema::create('stock_units', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('lpn', 40); // licence plate number printed on the pallet/carton label
            $t->uuid('warehouse_id');
            $t->uuid('product_id');
            $t->uuid('asn_line_id')->nullable();
            $t->string('lot_no', 60)->nullable();
            $t->date('expiry_date')->nullable();
            $t->unsignedInteger('quantity'); // base units
            $t->uuid('location_id')->nullable(); // null until putaway (stays at the receiving area)
            $t->string('status', 16); // QUARANTINE PENDING_PUTAWAY AVAILABLE REJECTED
            $t->timestampTz('received_at');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'lpn']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'warehouse_id', 'status']);
            $t->index(['tenant_id', 'product_id', 'status']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
            $t->foreign(['tenant_id', 'asn_line_id'])->references(['tenant_id', 'id'])->on('asn_lines');
            $t->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('storage_nodes');
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            // Append-only ledger of everything that changes stock; rows are never updated or deleted.
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('stock_unit_id');
            $t->string('type', 20); // RECEIVE QC_RELEASE QC_QUARANTINE QC_REJECT PUTAWAY
            $t->integer('quantity');
            $t->uuid('from_location_id')->nullable();
            $t->uuid('to_location_id')->nullable();
            $t->string('reason', 500)->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'stock_unit_id', 'created_at']);
            $t->foreign(['tenant_id', 'stock_unit_id'])->references(['tenant_id', 'id'])->on('stock_units');
        });
        Schema::create('putaway_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('stock_unit_id');
            $t->uuid('recommended_location_id')->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN DONE CANCELLED
            $t->uuid('confirmed_location_id')->nullable();
            $t->boolean('overridden')->default(false);
            $t->string('override_reason', 500)->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status']);
            $t->foreign(['tenant_id', 'stock_unit_id'])->references(['tenant_id', 'id'])->on('stock_units');
        });
        Schema::create('nonconformances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->string('source', 12); // RECEIPT (later: STOCKTAKE, OUTBOUND, AUDIT)
            $t->uuid('stock_unit_id')->nullable();
            $t->string('category', 20); // DAMAGED EXPIRED WRONG_ITEM QUANTITY TEMPERATURE OTHER
            $t->string('description', 1000);
            $t->string('disposition', 12)->default('QUARANTINE'); // QUARANTINE REJECT RELEASE
            $t->string('status', 8)->default('OPEN'); // OPEN CLOSED
            $t->foreignId('raised_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status']);
            $t->foreign(['tenant_id', 'stock_unit_id'])->references(['tenant_id', 'id'])->on('stock_units');
        });
    }

    public function down(): void
    {
        foreach (['nonconformances', 'putaway_tasks', 'stock_movements', 'stock_units', 'dock_bookings', 'asn_lines', 'asns', 'docks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
