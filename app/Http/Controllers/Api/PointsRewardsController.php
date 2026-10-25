<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/points/rewards: the price list a signed-in person sees on a platform. Only redeemable kinds (recognition
 * is never for sale), only active rewards; one that is out of stock or at its monthly limit is still listed, marked
 * `available: false`, so the screen can say so. Nothing internal leaves: no stock numbers, params or keys.
 */
class PointsRewardsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $takenThisMonth = Redemption::whereNotIn('status', ['failed', 'cancelled'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('reward_id, count(*) as taken')->groupBy('reward_id')->pluck('taken', 'reward_id');

        $rewards = Reward::where('active', true)->whereIn('kind', Reward::KINDS)
            ->orderBy('price_points')->orderBy('title')->orderBy('id')->get()
            ->map(fn (Reward $r) => [
                'id' => $r->id,
                'name' => $r->title,
                'kind' => $r->kind,
                'description' => $r->description,
                'cost_points' => (int) $r->price_points,
                'available' => ($r->stock === null || $r->stock > 0)
                    && ($r->monthly_cap === null || (int) ($takenThisMonth[$r->id] ?? 0) < $r->monthly_cap),
            ])->values();

        return response()->json($rewards);
    }
}
