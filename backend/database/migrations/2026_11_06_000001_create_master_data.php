<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Module 1 (Governance & Master Data): warehouses, storage hierarchy, products, packaging, partners and the transport integration setting. */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('tenant_id')->constrained();
        $t->string('code', 40);
        $t->string('name', 200);
        $t->boolean('active')->default(true);
        $t->timestampsTz();
        $t->unique(['tenant_id', 'code']);
        $t->unique(['tenant_id', 'id']);
    }

    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $t) {
            $this->base($t);
            $t->string('city', 100)->nullable();
            $t->string('address', 1000)->nullable();
            // The deepest level of the storage hierarchy this warehouse manages (the per-warehouse granularity limit).
            $t->string('granularity', 8)->default('BIN');
        });
        Schema::create('uoms', fn (Blueprint $t) => $this->base($t));
        Schema::create('product_categories', fn (Blueprint $t) => $this->base($t));
        Schema::create('products', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('category_id')->nullable();
            $t->uuid('base_uom_id');
            $t->string('barcode', 60)->nullable();
            $t->string('rotation_policy', 4)->default('FIFO');
            $t->boolean('track_lot')->default(true);
            $t->boolean('track_expiry')->default(false);
            $t->unsignedInteger('shelf_life_days')->nullable();
            $t->unsignedInteger('min_remaining_shelf_life_days')->nullable();
            $t->string('temperature_class', 8)->default('AMBIENT');
            $t->boolean('hazmat')->default(false);
            $t->unsignedBigInteger('weight_g')->nullable();
            $t->unsignedInteger('length_mm')->nullable();
            $t->unsignedInteger('width_mm')->nullable();
            $t->unsignedInteger('height_mm')->nullable();
            $t->foreign(['tenant_id', 'base_uom_id'])->references(['tenant_id', 'id'])->on('uoms');
            $t->index(['tenant_id', 'barcode']);
        });
        Schema::create('product_packaging', function (Blueprint $t) {
            $this->base($t);
            $t->uuid('product_id');
            $t->uuid('uom_id');
            $t->unsignedInteger('base_quantity');
            $t->string('barcode', 60)->nullable();
            $t->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
            $t->foreign(['tenant_id', 'uom_id'])->references(['tenant_id', 'id'])->on('uoms');
        });
        Schema::create('partners', function (Blueprint $t) {
            $this->base($t);
            $t->string('type', 10);
            $t->string('contact_name', 150)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('address', 1000)->nullable();
            $t->string('tax_id', 32)->nullable();
            $t->index(['tenant_id', 'type']);
        });
        Schema::create('storage_nodes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('warehouse_id');
            $t->uuid('parent_id')->nullable();
            $t->string('level', 8); // ZONE > AISLE > RACK > SHELF > BIN
            $t->string('code', 40);
            $t->string('name', 200);
            $t->boolean('active')->default(true);
            $t->boolean('blocked')->default(false);
            $t->string('blocked_reason', 500)->nullable();
            $t->unsignedInteger('capacity_base_units')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->unique(['tenant_id', 'warehouse_id', 'code']);
            $t->index(['tenant_id', 'warehouse_id', 'parent_id']);
            $t->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses');
            $t->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('storage_nodes');
        });
        Schema::create('tenant_settings', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('key', 60);
            $t->json('value');
            $t->timestampTz('updated_at');
            $t->primary(['tenant_id', 'key']);
        });
        Schema::table('memberships', function (Blueprint $t) {
            $t->foreign(['supplier_id'])->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropForeign(['supplier_id']));
        foreach (['tenant_settings', 'storage_nodes', 'partners', 'product_packaging', 'products', 'product_categories', 'uoms', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
