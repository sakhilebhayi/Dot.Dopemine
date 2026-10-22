<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointsDigest;
use App\Services\Points\DigestPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The weekly / monthly summaries InfoDot pulls and delivers itself (signed server to server, VerifyPointsSignature).
 * Nothing here carries an address or any contribution content. See docs/points-digests-api.md.
 */
class PointsDigestController extends Controller
{
    private const OUTCOMES = ['delivered', 'retry', 'skipped'];

    public function __construct(private readonly DigestPull $pull) {}

    public function index(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'period' => ['required', 'string', 'in:'.implode(',', PointsDigest::PERIODS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        $data = $input->validated();

        return response()->json($this->pull->due((string) $data['period'], (int) ($data['limit'] ?? 100)));
    }

    public function result(Request $request, string $digest): JsonResponse
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $digest) !== 1) {
            return $this->notFound();
        }

        $input = Validator::make($request->json()->all(), ['outcome' => ['required', 'string', 'in:'.implode(',', self::OUTCOMES)]]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        $state = $this->pull->record(strtolower($digest), (string) $input->validated()['outcome']);

        if ($state === null) {
            return $this->notFound();
        }

        return response()->json(['digest_id' => strtolower($digest), 'state' => $state]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['code' => 'digest_not_found', 'message' => 'No such digest.'], 404);
    }

    /** @param array<string, list<string>> $errors */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json(['code' => 'validation_failed', 'message' => 'The request is not valid.', 'errors' => $errors], 422);
    }
}
