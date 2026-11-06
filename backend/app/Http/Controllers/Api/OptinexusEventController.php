<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integration\CarrierFeedback;
use App\Domain\Integration\Optinexus\TenantProvisioner;
use App\Domain\Integration\Optinexus\WebhookSignature;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the OptiNexus event broker (the assignment of OptiStash to a tenant). Authenticated by the webhook signature only (no session, no CSRF); the tenant
 * comes from the event's OptiNexus tenant, never from the request. A refusal is a 4xx that OptiNexus does not retry; a
 * deployment without a secret refuses everything.
 */
class OptinexusEventController extends Controller
{
    public function receive(Request $request, TenantProvisioner $tenants): JsonResponse
    {
        $valid = WebhookSignature::valid(
            (string) config('optistash.optinexus.events.webhook_secret'),
            (string) $request->header('Opti-Webhook-Id'),
            (string) $request->header('Opti-Webhook-Timestamp'),
            $request->getContent(),
            (string) $request->header('Opti-Webhook-Signature'),
            (int) config('optistash.optinexus.events.tolerance_seconds'),
        );
        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.', 'code' => 'WEBHOOK_SIGNATURE_INVALID'], 401);
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid body.', 'code' => 'WEBHOOK_BODY_INVALID'], 400);
        }

        // Tenant events from OptiNexus create the tenant; the carrier's status feedback updates a shipment; any other event is accepted and ignored.
        $result = match (true) {
            $tenants->handles($event) => $tenants->provision($event),
            ($event['event_type'] ?? null) === CarrierFeedback::RECEIVED => CarrierFeedback::apply($event),
            default => 'IGNORED',
        };
        // An unusable feedback event is a 4xx: OptiNexus does not retry it.
        if ($result === 'invalid') {
            return response()->json(['message' => 'Invalid carrier status.', 'code' => 'CARRIER_STATUS_INVALID'], 422);
        }

        return response()->json(['result' => $result], 202);
    }
}
