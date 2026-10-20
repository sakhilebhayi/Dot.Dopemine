<?php

namespace App\Services\Points;

use Illuminate\Http\Request;

/** Verifies `X-InfoDot-Signature` = HMAC-SHA256 over `{X-InfoDot-Timestamp}.{raw body}`, inside a 5 minute window. */
class IngestSignature
{
    public static function valid(Request $request, string $secret): bool
    {
        $timestamp = (int) $request->header('X-InfoDot-Timestamp');
        $signature = (string) $request->header('X-InfoDot-Signature');

        // Always do the HMAC, even for an unconfigured platform, so a missing secret costs the same as a wrong signature.
        $key = $secret !== '' ? $secret : hash('sha256', 'dopemine-ingest-unconfigured-platform');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $key);
        $fresh = $timestamp !== 0 && abs(now()->timestamp - $timestamp) <= (int) config('points.ingest.tolerance', 300);
        $given = str_starts_with($signature, 'sha256=') ? $signature : 'sha256='.$signature;

        return hash_equals($expected, $given) && $fresh && $secret !== '';
    }
}
