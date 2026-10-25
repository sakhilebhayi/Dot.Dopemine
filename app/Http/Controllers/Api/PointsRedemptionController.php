<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Points\InsufficientPoints;
use App\Exceptions\Points\RedemptionRefused;
use App\Exceptions\Points\RewardMonthlyLimitReached;
use App\Exceptions\Points\RewardOutOfStock;
use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Models\Reward;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * A person spending Dot Points from a platform. The platform's SERVER calls this, signed (VerifyPointsSignature);
 * the caller names the person and this acts on exactly that uuid, never on anything else. All the money logic stays
 * in RewardRedemptions / PointsLedger (balance check, oldest-lot-first spend, stock claim, all in one transaction);
 * this only translates it to HTTP with stable error codes.
 */
class PointsRedemptionController extends Controller
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const LIST_LIMIT = 100;

    public function __construct(private readonly PointsLedger $ledger, private readonly RewardRedemptions $redemptions) {}

    public function index(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), ['user_uuid' => ['required', 'string', 'regex:'.self::UUID]]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        $rows = Redemption::with('reward')->where('user_uuid', $request->query('user_uuid'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return response()->json($rows->map(function (Redemption $r) {
            $returned = in_array($r->status, ['failed', 'cancelled'], true);

            return [
                'redemption_id' => $r->uuid,
                'reward' => ['name' => $r->reward?->title, 'kind' => $r->reward?->kind],
                'points_spent' => (int) $r->points,
                'status' => $r->status,
                'requested_at' => $r->created_at->toIso8601String(),
                'resolved_at' => $r->status === 'pending' ? null : $r->updated_at->toIso8601String(),
                'points_returned' => $returned,
                'message' => $returned ? 'Your points were returned.' : null,
            ];
        })->values());
    }

    public function store(Request $request): JsonResponse
    {
        $input = Validator::make($request->json()->all(), [
            'user_uuid' => ['required', 'string', 'regex:'.self::UUID],
            'reward_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        if ($input->fails()) {
            return $this->validationFailed($input->errors()->toArray());
        }

        $userUuid = (string) $input->validated()['user_uuid'];
        $rewardId = (int) $input->validated()['reward_id'];

        // The caller's key is scoped to the person, so one person can never replay (or collide with) another's.
        $key = 'api:'.$userUuid.':'.$input->validated()['idempotency_key'];

        if (($existing = Redemption::where('idempotency_key', $key)->first()) !== null) {
            return $this->replay($existing, $rewardId);
        }

        $limiter = 'points-redeem:'.$userUuid;
        $max = (int) config('points.redemptions.rate_limit', 10);

        if (RateLimiter::tooManyAttempts($limiter, $max)) {
            $wait = RateLimiter::availableIn($limiter);

            return response()->json([
                'code' => 'rate_limited',
                'message' => 'Too many redemptions in the last hour. Try again later.',
                'retry_after' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        RateLimiter::hit($limiter, (int) config('points.redemptions.rate_window_seconds', 3600));

        $reward = Reward::where('active', true)->whereIn('kind', Reward::KINDS)->find($rewardId);

        if ($reward === null) {
            return $this->error('reward_unavailable', 'This reward is not available.', 404);
        }

        try {
            $redemption = $this->redemptions->redeem($userUuid, $reward, $key);
        } catch (InsufficientPoints $e) {
            return $this->error('insufficient_points', 'You do not have enough points for this reward.', 409, ['points_needed' => $e->needed, 'balance' => $e->available]);
        } catch (RewardOutOfStock) {
            return $this->error('out_of_stock', 'This reward is out of stock.', 409);
        } catch (RewardMonthlyLimitReached) {
            return $this->error('monthly_limit_reached', 'This reward has reached its limit for this month.', 409);
        } catch (RedemptionRefused) {
            // RewardNotAvailable, or any future refusal: the safe answer is that it cannot be bought now.
            return $this->error('reward_unavailable', 'This reward is not available.', 404);
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced; the other one won and this one rolled back whole.
            $existing = Redemption::where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $this->replay($existing, $rewardId);
            }

            throw new \RuntimeException('Redemption conflict could not be resolved.');
        }

        return response()->json($this->result($redemption), 201);
    }

    private function replay(Redemption $existing, int $rewardId): JsonResponse
    {
        if ((int) $existing->reward_id !== $rewardId) {
            return $this->error('idempotency_key_reused', 'This idempotency key was already used for a different reward.', 409);
        }

        return response()->json($this->result($existing), 200);
    }

    /** @return array<string, mixed> */
    private function result(Redemption $redemption): array
    {
        $redemption->loadMissing('reward');

        return [
            'redemption_id' => $redemption->uuid,
            'status' => $redemption->status,
            'reward' => ['id' => $redemption->reward_id, 'name' => $redemption->reward->title, 'kind' => $redemption->reward->kind],
            'points_spent' => (int) $redemption->points,
            'balance_after' => $this->ledger->balance($redemption->user_uuid),
        ];
    }

    /** @param array<string, mixed> $extra */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message] + $extra, $status);
    }

    /** @param array<string, list<string>> $errors */
    private function validationFailed(array $errors): JsonResponse
    {
        return response()->json(['code' => 'validation_failed', 'message' => 'The request is not valid.', 'errors' => $errors], 422);
    }
}
