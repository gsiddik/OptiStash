<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invitation lets a supplier's person choose their own password. Only the SHA-256 of the one-time token is stored.
        Schema::create('supplier_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('partner_id');
            $t->string('name', 150);
            $t->string('email', 254);
            $t->char('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->unsignedBigInteger('invited_by')->nullable();
            $t->timestampTz('created_at');
            $t->index(['tenant_id', 'partner_id']);
            $t->foreign(['tenant_id', 'partner_id'])->references(['tenant_id', 'id'])->on('partners');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invitations');
    }
};
