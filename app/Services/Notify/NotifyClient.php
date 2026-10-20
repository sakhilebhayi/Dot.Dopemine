<?php

namespace App\Services\Notify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened to this company, tell this person" (POST /api/ingest/events). Same client as
 * Dot.Tasks and Dot.Auction, minus their queue: Dopemine calls it from a scheduled command that does its own
 * bookkeeping and retries.
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The signature is made at SEND
 * time, so a retry carries a fresh timestamp (Notify only accepts five minutes either way).
 *
 * Harmless by design: with no NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET it does nothing, the call has a short
 * timeout, nothing here throws, and nothing it logs contains an address, a payload or the secret.
 */
class NotifyClient
{
    public function enabled(): bool
    {
        return filled(config('services.notify.url')) && filled(config('services.notify.secret'));
    }

    public function platform(): string
    {
        return (string) config('services.notify.platform', 'dopemine');
    }

    /** @param  array<string, mixed>  $payload */
    public function send(array $payload): NotifyResult
    {
        if (! $this->enabled()) {
            return new NotifyResult(NotifyResult::GIVE_UP, null, 'not_configured');
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('services.notify.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.notify.url'));
        } catch (ConnectionException) {
            return new NotifyResult(NotifyResult::RETRY, null, 'unreachable');
        } catch (Throwable $e) {
            Log::warning('Dot.Notify call failed unexpectedly.', ['error' => class_basename($e)]);

            return new NotifyResult(NotifyResult::RETRY, null, 'exception');
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $said = $response->json('status');

            return new NotifyResult(NotifyResult::DONE, is_string($said) ? substr($said, 0, 24) : null);
        }

        if ($status === 429 || $status >= 500) {
            return new NotifyResult(NotifyResult::RETRY, null, 'http_'.$status);
        }

        if ($status === 401 || $status === 403) {
            return new NotifyResult(NotifyResult::UNAUTHORISED, null, 'http_'.$status);
        }

        // 404: the company is unknown to Notify. 413/422: the payload is unusable. Retrying cannot fix any of them.
        return new NotifyResult(NotifyResult::GIVE_UP, null, 'http_'.$status);
    }
}
