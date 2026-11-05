<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Module 4 (Outbound): orders, allocation (reservations on stock units), waves and pick tasks, packing, shipments and dispatch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', fn (Blueprint $t) => $t->unsignedInteger('reserved_qty')->default(0));
        Schema::create('outbound_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('customer_id');
            $t->uuid('warehouse_id');
            // DRAFT RELEASED ALLOCATED PICKING PICKED PACKED DISPATCHED CANCELLED
            $t->string('status', 12)->default('DRAFT');
            $t->date('required_date');
            $t->string('reference', 120)->nullable();
            $t->string('ship_to', 1000);
            $t->string('notes', 1000)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status']);
            $t->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('partners');
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('outbound_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('order_id');
            $t->unsignedSmallInteger('line_no');
            $t->uuid('product_id');
            $t->unsignedInteger('ordered_qty');
            $t->unsignedInteger('allocated_qty')->default(0);
            $t->unsignedInteger('picked_qty')->default(0);
            $t->unsignedInteger('packed_qty')->default(0);
            $t->unique(['order_id', 'line_no']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('outbound_orders');
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
        });
        Schema::create('allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('line_id');
            $t->uuid('stock_unit_id');
            $t->unsignedInteger('quantity');
            $t->string('status', 10)->default('RESERVED'); // RESERVED PICKED RELEASED
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'stock_unit_id', 'status']);
            $t->foreign(['tenant_id', 'line_id'])->references(['tenant_id', 'id'])->on('outbound_lines');
            $t->foreign(['tenant_id', 'stock_unit_id'])->references(['tenant_id', 'id'])->on('stock_units');
        });
        Schema::create('waves', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('warehouse_id');
            $t->string('type', 8); // WAVE BATCH CLUSTER
            $t->string('status', 10)->default('RELEASED'); // RELEASED COMPLETED CANCELLED
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
        });
        Schema::create('pick_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('wave_id');
            $t->uuid('allocation_id');
            $t->uuid('stock_unit_id');
            $t->uuid('location_id');
            $t->uuid('product_id');
            $t->uuid('order_id');
            $t->unsignedInteger('sequence'); // pick path order (location code; BATCH: product first)
            $t->unsignedSmallInteger('slot')->nullable(); // CLUSTER: the tote slot of the order
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('picked_qty')->default(0);
            $t->string('status', 8)->default('OPEN'); // OPEN DONE SHORT
            $t->string('short_reason', 500)->nullable();
            $t->foreignId('picked_by')->nullable()->constrained('users');
            $t->timestampTz('picked_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'wave_id', 'status']);
            $t->foreign(['tenant_id', 'wave_id'])->references(['tenant_id', 'id'])->on('waves');
            $t->foreign(['tenant_id', 'allocation_id'])->references(['tenant_id', 'id'])->on('allocations');
        });
        Schema::create('packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('order_id');
            $t->string('status', 8)->default('OPEN'); // OPEN CLOSED
            $t->foreignId('packed_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('outbound_orders');
        });
        Schema::create('package_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('package_id');
            $t->uuid('line_id');
            $t->unsignedInteger('quantity');
            $t->foreign(['tenant_id', 'package_id'])->references(['tenant_id', 'id'])->on('packages');
            $t->foreign(['tenant_id', 'line_id'])->references(['tenant_id', 'id'])->on('outbound_lines');
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('number', 40);
            $t->uuid('warehouse_id');
            $t->string('mode', 16); // OPTIDELI THIRD_PARTY_API MANUAL, copied from the transport setting when created
            $t->uuid('carrier_id')->nullable();
            $t->string('truck_plate', 30)->nullable();
            $t->string('driver_name', 150)->nullable();
            $t->string('status', 10)->default('LOADING'); // LOADING DISPATCHED CANCELLED
            $t->timestampTz('dispatched_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'carrier_id'])->references(['tenant_id', 'id'])->on('partners');
        });
        Schema::create('shipment_orders', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('shipment_id');
            $t->uuid('order_id');
            $t->primary(['shipment_id', 'order_id']);
            $t->unique(['tenant_id', 'order_id']);
            $t->foreign(['tenant_id', 'shipment_id'])->references(['tenant_id', 'id'])->on('shipments');
            $t->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('outbound_orders');
        });
    }

    public function down(): void
    {
        foreach (['shipment_orders', 'shipments', 'package_lines', 'packages', 'pick_tasks', 'waves', 'allocations', 'outbound_lines', 'outbound_orders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('stock_units', fn (Blueprint $t) => $t->dropColumn('reserved_qty'));
    }
};
