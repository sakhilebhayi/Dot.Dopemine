<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RewardEvent;
use App\Services\Points\ContributionEventProcessor;
use App\Services\Points\IngestSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/ingest/contributions: a platform tells the points service what happened to a contribution.
 *
 * The platform is identified by the secret that signed the request, never by a field in it. An unsigned, stale or
 * wrongly signed request is refused before anything is read. An event id seen before is acknowledged and ignored.
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

        $result = in_array($data['type'], ContributionEventProcessor::SUPPORTED, true)
            ? $processor->handle($platform, $data)
            : ['outcome' => 'unsupported', 'detail' => 'This event type is not handled yet.'];

        RewardEvent::create([
            'event_id' => $data['event_id'],
            'platform' => $platform,
            'type' => $data['type'],
            'user_uuid' => $data['user_uuid'],
            'outcome' => $result['outcome'],
            'detail' => $result['detail'],
        ]);

        return response()->json(['status' => $result['outcome']], 202);
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
