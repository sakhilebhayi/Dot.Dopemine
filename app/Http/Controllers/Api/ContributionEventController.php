<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RewardEvent;
use App\Services\Points\ContributionEventProcessor;
use App\Services\Points\IngestSignature;
use App\Services\Points\SeminarHostedGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/ingest/contributions: a platform tells the points service what happened to a contribution.
 *
 * The platform is identified by the secret that signed the request, never by a field in it. An unsigned, stale or
 * wrongly signed request is refused before anything is read. An event id seen before is acknowledged and ignored.
 *
 * An event that refers to a contribution this service has not been told about yet (an approval or a verification
 * that overtook the publication it judges) is answered 409 `contribution_not_ready` with `Retry-After`, and its event id is NOT
 * remembered: InfoDot's outbox retries 409, 425, 429 and 5xx with growing delays (and holds later events for that contribution behind it), so the same event is processed afresh once
 * the publication has arrived. Every other outcome is final and is answered 202.
 */
class ContributionEventController extends Controller
{
    public function __invoke(Request $request, ContributionEventProcessor $processor): JsonResponse
    {
        $platform = $this->platformFor($request);

        abort_if($platform === null, 401, 'Invalid signature.');

        $data = Validator::make($request->json()->all(), [
            'event_id' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'starts_with:'.$platform.'.', 'max:80'],
            'occurred_at' => ['required', 'date'],
            'user_uuid' => ['required', 'uuid'],
            'contribution_ref' => ['required', 'string', 'max:160'],
            'actor_uuid' => ['nullable', 'uuid'],
            'tenant_uuid' => ['nullable', 'uuid'],
            'visibility' => ['nullable', 'in:public,company'],
            'facts' => ['nullable', 'array'],
        ])->validate();

        if (RewardEvent::where('event_id', $data['event_id'])->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        // A seminar that does not qualify, or an event carrying anything beyond its whitelist, is refused here: 422, nothing
        // recorded (not even the event id, so the same event can be sent again once the figures or thresholds change).
        if ($data['type'] === SeminarHostedGate::TYPE && ($refusal = SeminarHostedGate::refusal($request->json()->all())) !== null) {
            return response()->json($refusal, 422);
        }

        $result = in_array($data['type'], ContributionEventProcessor::SUPPORTED, true)
            ? $processor->handle($platform, $data)
            : ['outcome' => 'unsupported', 'detail' => 'This event type is not handled yet.'];

        if ($result['outcome'] === ContributionEventProcessor::NOT_READY) {
            return response()->json([
                'code' => 'contribution_not_ready',
                'message' => $result['detail'],
                'status' => $result['outcome'],
            ], 409, ['Retry-After' => '60']);
        }

        RewardEvent::create([
            'event_id' => $data['event_id'],
            'platform' => $platform,
            'type' => $data['type'],
            'user_uuid' => $data['user_uuid'],
            'outcome' => $result['outcome'],
            'detail' => $result['detail'],
        ]);

        return response()->json(array_filter(['status' => $result['outcome'], 'detail' => $result['detail']], fn ($value) => $value !== null), 202);
    }

    /** The platform whose configured secret signed this request, or null. */
    private function platformFor(Request $request): ?string
    {
        foreach ((array) config('points.ingest.secrets') as $platform => $secret) {
            if (IngestSignature::valid($request, (string) $secret)) {
                return (string) $platform;
            }
        }

        return null;
    }
}
