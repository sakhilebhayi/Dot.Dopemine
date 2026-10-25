<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Points\ExpiryWarningPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The expiry heads-ups InfoDot pulls and delivers itself (signed server to server, VerifyPointsSignature). Nothing
 * here carries an address; each row is only `{warning_id, user_uuid, points, expires_on}`. See
 * docs/points-expiry-warnings-api.md.
 */
class PointsExpiryWarningController extends Controller
{
    private const OUTCOMES = ['delivered', 'retry', 'permanent_failure'];

    public function __construct(private readonly ExpiryWarningPull $pull) {}

    public function index(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), ['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        return response()->json($this->pull->due((int) ($input->validated()['limit'] ?? 100)));
    }

    public function result(Request $request, string $warning): JsonResponse
    {
        if (! ctype_digit($warning) || strlen($warning) > 18) {
            return $this->notFound();
        }

        $input = Validator::make($request->json()->all(), ['outcome' => ['required', 'string', 'in:'.implode(',', self::OUTCOMES)]]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        $state = $this->pull->record((int) $warning, (string) $input->validated()['outcome']);

        if ($state === null) {
            return $this->notFound();
        }

        return response()->json(['warning_id' => (int) $warning, 'state' => $state]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['code' => 'warning_not_found', 'message' => 'No such expiry warning.'], 404);
    }

    /** @param array<string, list<string>> $errors */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json(['code' => 'validation_failed', 'message' => 'The request is not valid.', 'errors' => $errors], 422);
    }
}
