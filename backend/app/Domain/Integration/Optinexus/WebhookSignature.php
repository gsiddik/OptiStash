<?php

namespace App\Domain\Integration\Optinexus;

/**
 * Checks the signature of an OptiNexus event broker webhook:
 * `Opti-Webhook-Signature: v1=hex(HMAC-SHA256(secret, "<deliveryId>.<timestamp>.<raw body>"))`, with a timestamp that is not
 * older (or newer) than the tolerance, so a captured delivery cannot be replayed later.
 */
final class WebhookSignature
{
    public static function valid(string $secret, string $deliveryId, string $timestamp, string $body, string $signature, int $tolerance): bool
    {
        if ($secret === '' || $deliveryId === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }
        if (! str_starts_with($signature, 'v1=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $deliveryId.'.'.$timestamp.'.'.$body, $secret), substr($signature, 3));
    }
}
