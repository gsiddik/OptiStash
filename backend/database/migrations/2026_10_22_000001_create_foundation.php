<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug')->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->string('nexus_tenant_code', 80)->nullable();
            $t->timestampsTz();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role');
            $t->boolean('active')->default(true);
            // Set for a supplier's portal user (the partner it belongs to); such a membership never reaches the internal screens.
            $t->uuid('supplier_id')->nullable();
            $t->unique(['tenant_id', 'user_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->uuid('subject_id');
            $t->json('metadata');
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('type');
            $t->json('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('published_at')->nullable();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id');
            $t->string('operation');
            $t->string('key', 100);
            $t->string('fingerprint', 64);
            $t->uuid('resource_id');
            $t->unique(['tenant_id', 'operation', 'key']);
        });
        // Gap-free document numbers per tenant, kind and period (ASN, receipts, orders, counts ...).
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('kind', 20);
            $t->string('period', 8);
            $t->unsignedBigInteger('last_value')->default(0);
            $t->timestampTz('updated_at')->nullable();
            $t->primary(['tenant_id', 'kind', 'period']);
        });
    }

    public function down(): void
    {
        foreach (['document_sequences', 'idempotency_keys', 'outbox_events', 'audit_events', 'memberships', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
