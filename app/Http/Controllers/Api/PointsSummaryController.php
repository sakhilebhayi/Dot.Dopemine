<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\RewardRule;
use App\Services\Points\IngestSignature;
use App\Services\Points\PointsLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/points/{user_uuid}: what a person's profile shows. Signed by the calling platform like the intake, so
 * only a configured platform can read, and it returns the three things the profile keeps apart: the points that can
 * be redeemed (with when the next ones lapse), the history of every award, spend and expiry with its reason, and
 * the lifetime total earned. No contribution content, no other person's data.
 */
class PointsSummaryController extends Controller
{
    public function __invoke(Request $request, string $userUuid, PointsLedger $ledger): JsonResponse
    {
        abort_unless($this->signed($request), 401, 'Invalid signature.');
        abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userUuid) === 1, 422);

        $lots = $ledger->openLots($userUuid);
        $soon = $lots->filter(fn (LedgerEntry $l) => $l->expires_at->lte(now()->addDays(30)));
        $notes = RewardRule::pluck('note', 'key');

        return response()->json([
            'balance' => (int) $lots->sum('remaining'),
            'total_earned' => (int) LedgerEntry::where('user_uuid', $userUuid)->whereIn('type', LedgerEntry::LOT_TYPES)->where('type', LedgerEntry::AWARD)->sum('points'),
            'expiring_soon' => $soon->isEmpty() ? null : ['points' => (int) $soon->sum('remaining'), 'first_at' => $soon->min('expires_at')->toIso8601String()],
            'next_expiry' => $lots->isEmpty() ? null : $lots->first()->expires_at->toIso8601String(),
            'next_expiry_points' => $lots->isEmpty() ? null : (int) $lots->first()->remaining,
            'history' => $ledger->history($userUuid, 50)->map(fn (LedgerEntry $e) => [
                'at' => $e->created_at->toIso8601String(),
                'type' => $e->type,
                'points' => $e->points,
                'label' => $e->rule_key ? ($notes[$e->rule_key] ?? $e->rule_key) : ($e->reason ?? $e->type),
                'rule' => $e->rule_key,
                'rule_version' => $e->rule_version,
                'ref' => $e->contribution_ref,
                'expires_at' => $e->expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function signed(Request $request): bool
    {
        foreach ((array) config('points.ingest.secrets') as $secret) {
            if (IngestSignature::valid($request, (string) $secret)) {
                return true;
            }
        }

        return false;
    }
}
