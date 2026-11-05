<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Module 6: IoT gateways, devices (tags and sensors bound to locations or stock units), telemetry, alerts, and outbox delivery tracking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iot_gateways', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('name', 150);
            $t->string('protocol', 12); // REST MQTT_BRIDGE
            $t->char('token_hash', 64)->unique(); // sha256 of the bearer token; the token itself is shown once
            $t->boolean('active')->default(true);
            $t->timestampTz('last_seen_at')->nullable();
            $t->foreignId('created_by')->constrained('users'); // the actor recorded for what a gateway causes (alerts, locks)
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('iot_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('gateway_id')->nullable();
            $t->string('code', 40);
            $t->string('name', 200);
            $t->string('external_id', 100); // the identifier the gateway sends
            $t->string('mqtt_topic', 200)->nullable();
            $t->string('metric', 12); // TEMPERATURE HUMIDITY WEIGHT RFID OTHER
            $t->string('unit', 12)->nullable();
            $t->decimal('min_value', 12, 3)->nullable();
            $t->decimal('max_value', 12, 3)->nullable();
            $t->string('binding_type', 10)->default('NONE'); // NONE LOCATION STOCK_UNIT
            $t->uuid('binding_id')->nullable();
            $t->boolean('auto_lock')->default(false); // lock the bound stock when a limit is breached
            $t->unsignedSmallInteger('stale_after_minutes')->nullable();
            $t->boolean('active')->default(true);
            $t->decimal('last_value', 12, 3)->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'external_id']);
            $t->unique(['tenant_id', 'mqtt_topic']);
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'gateway_id'])->references(['tenant_id', 'id'])->on('iot_gateways');
        });
        Schema::create('telemetry_readings', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('device_id');
            $t->decimal('value', 12, 3);
            $t->timestampTz('recorded_at');
            $t->timestampTz('received_at');
            $t->index(['device_id', 'recorded_at']);
            $t->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('iot_devices');
        });
        Schema::create('telemetry_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('device_id');
            $t->string('kind', 5); // HIGH LOW STALE
            $t->decimal('value', 12, 3)->nullable();
            $t->decimal('limit_value', 12, 3)->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN ACKNOWLEDGED CLOSED
            $t->timestampTz('raised_at');
            $t->timestampTz('last_seen_at');
            $t->unsignedInteger('occurrences')->default(1);
            $t->unsignedInteger('locked_units')->default(0);
            $t->uuid('nonconformance_id')->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users');
            $t->string('note', 500)->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id']);
            $t->index(['tenant_id', 'status']);
            $t->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('iot_devices');
        });
        Schema::table('outbox_events', function (Blueprint $t) {
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->index(['published_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $t) {
            $t->dropIndex(['published_at', 'next_attempt_at']);
            $t->dropColumn(['attempts', 'next_attempt_at', 'last_error']);
        });
        foreach (['telemetry_alerts', 'telemetry_readings', 'iot_devices', 'iot_gateways'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
