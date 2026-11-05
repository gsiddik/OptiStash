<?php

namespace App\Domain\Shared;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per tenant, kind and period. The counter row is incremented inside the caller's transaction, so a
 * number is only consumed when the document that carries it is saved: a rolled-back document gives its number back.
 */
final class DocumentNumbers
{
    public static function next(string $tenantId, string $kind, string $period): int
    {
        $row = DB::selectOne(
            'INSERT INTO document_sequences (tenant_id, kind, period, last_value, updated_at) VALUES (?, ?, ?, 1, now())
             ON CONFLICT (tenant_id, kind, period) DO UPDATE SET last_value = document_sequences.last_value + 1, updated_at = now()
             RETURNING last_value',
            [$tenantId, $kind, $period],
        );

        return (int) $row->last_value;
    }
}
