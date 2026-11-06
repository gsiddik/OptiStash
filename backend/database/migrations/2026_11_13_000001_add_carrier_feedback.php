<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Status feedback from the carrier system (OptiDeli) for shipments sent in OPTIDELI mode: the latest status on the shipment and an immutable history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            $t->string('carrier_status', 12)->nullable();
            $t->timestampTz('carrier_status_at')->nullable();
            $t->string('carrier_reason', 300)->nullable();
        });
        Schema::create('shipment_carrier_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('shipment_id');
            $t->uuid('source_event_id');
            $t->string('status', 12);
            $t->string('reason', 300)->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampTz('received_at');
            $t->unique(['tenant_id', 'source_event_id']);
            $t->index(['tenant_id', 'shipment_id', 'occurred_at']);
            $t->foreign(['tenant_id', 'shipment_id'])->references(['tenant_id', 'id'])->on('shipments');
        });
        DB::statement("ALTER TABLE shipment_carrier_events ADD CONSTRAINT shipment_carrier_events_status_check CHECK (status IN ('ACCEPTED','REJECTED','PICKED_UP','DELIVERED','CANCELLED'))");
        DB::statement('CREATE TRIGGER shipment_carrier_events_append_only BEFORE UPDATE OR DELETE ON shipment_carrier_events FOR EACH ROW EXECUTE FUNCTION forbid_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_carrier_events');
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropColumn(['carrier_status', 'carrier_status_at', 'carrier_reason']);
        });
    }
};
