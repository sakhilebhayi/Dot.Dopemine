<?php

namespace App\Http\Middleware;

use App\Services\Points\IngestSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signature check for the server-to-server /api/points endpoints: the same scheme as the intake and the summary
 * (`X-InfoDot-Timestamp` + `X-InfoDot-Signature`, HMAC-SHA256 over `{timestamp}.{raw body}`, 300 s window, one
 * secret per platform). A GET has no body, so it is signed over the empty string, exactly like the summary.
 */
class VerifyPointsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ((array) config('points.ingest.secrets') as $secret) {
            if (IngestSignature::valid($request, (string) $secret)) {
                return $next($request);
            }
        }

        return response()->json(['code' => 'invalid_signature', 'message' => 'Invalid signature.'], 401);
    }
}
