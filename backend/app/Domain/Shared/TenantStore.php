<?php

namespace App\Domain\Shared;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantStore
{
    public function __construct(public readonly string $tenantId, public readonly int $actorId) {}

    public function query(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenantId);
    }

    public function find(string $table, string $id, bool $lock = false): object
    {
        $query = $this->query($table)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function event(string $action, string $subject, array $metadata = []): void
    {
        $id = (string) Str::uuid();
        DB::table('audit_events')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'actor_id' => $this->actorId, 'action' => $action, 'subject_id' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenantId, 'type' => $action.'.v1', 'payload' => json_encode(['event_id' => $id, 'tenant_id' => $this->tenantId, 'subject_id' => $subject, 'occurred_at' => now()->toIso8601String(), 'data' => $metadata], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function idempotent(string $operation, string $key, array $data, \Closure $create): object
    {
        return DB::transaction(function () use ($operation, $key, $data, $create) {
            // Serializes mutations for one tenant, including first insertion of an idempotency key.
            DB::table('tenants')->where('id', $this->tenantId)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = $this->query('idempotency_keys')->where('operation', $operation)->where('key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Idempotency key reused with a different request.');

                return $this->find(match ($operation) {
                    'order.create' => 'orders', 'dispatch.plan' => 'dispatch_plans', 'pod.create' => 'proofs_of_delivery', 'appointment.create' => 'dock_appointments', 'invoice.create' => 'freight_invoices', 'claim.create' => 'accessorial_claims', 'vendor.create' => 'vendors', default => throw new \LogicException('Unknown idempotency operation.')
                }, $existing->resource_id);
            }
            $resource = $create();
            DB::table('idempotency_keys')->insert(['tenant_id' => $this->tenantId, 'operation' => $operation, 'key' => $key, 'fingerprint' => $fingerprint, 'resource_id' => $resource->id]);

            return $resource;
        }, 3);
    }
}
