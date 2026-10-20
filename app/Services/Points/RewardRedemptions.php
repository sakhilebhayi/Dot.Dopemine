<?php

namespace App\Services\Points;

use App\Exceptions\Points\InsufficientPoints;
use App\Exceptions\Points\RedemptionRefused;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Support\Facades\DB;

/**
 * Spending points on the price list. The points leave the ledger immediately (oldest lot first); the reward is
 * then fulfilled by whoever owns it. Until Dot.Billing, the entitlement service and the marketplace have hooks
 * the fulfilment is MANUAL: an operator marks the redemption fulfilled, and the screen says so. A redemption that
 * fails or is cancelled gives the points back as a new lot.
 */
class RewardRedemptions
{
    public function __construct(private readonly PointsLedger $ledger) {}

    /**
     * @throws RedemptionRefused
     * @throws InsufficientPoints
     */
    public function redeem(string $userUuid, Reward $reward, string $idempotencyKey): Redemption
    {
        if (($existing = Redemption::where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        if (! $reward->active) {
            throw new RedemptionRefused('This reward is not available.');
        }

        return DB::transaction(function () use ($userUuid, $reward, $idempotencyKey) {
            if ($reward->monthly_cap !== null) {
                $taken = Redemption::where('reward_id', $reward->id)
                    ->whereNotIn('status', ['failed', 'cancelled'])
                    ->where('created_at', '>=', now()->startOfMonth())->count();

                if ($taken >= $reward->monthly_cap) {
                    throw new RedemptionRefused('This reward has reached its limit for this month. It resets next month.');
                }
            }

            $this->ledger->redeem($userUuid, $reward->price_points, 'redemption:'.$idempotencyKey, 'Redeemed: '.$reward->title, ['reward' => $reward->key]);

            return Redemption::create([
                'idempotency_key' => $idempotencyKey,
                'user_uuid' => $userUuid,
                'reward_id' => $reward->id,
                'points' => $reward->price_points,
                'status' => 'pending',
            ]);
        });
    }

    public function fulfil(Redemption $redemption, string $reference, string $by): Redemption
    {
        abort_unless($redemption->status === 'pending', 422, 'Only a pending redemption can be fulfilled.');
        $redemption->forceFill(['status' => 'fulfilled', 'fulfilment_ref' => $reference, 'fulfilled_by' => $by])->save();

        return $redemption;
    }

    /** A redemption that could not be delivered, or that was cancelled: the points come back as a new lot. */
    public function fail(Redemption $redemption, string $note, bool $cancelled = false): Redemption
    {
        abort_unless($redemption->status === 'pending', 422, 'Only a pending redemption can be failed or cancelled.');

        DB::transaction(function () use ($redemption, $note, $cancelled): void {
            $entries = LedgerEntry::where('user_uuid', $redemption->user_uuid)
                ->where('idempotency_key', 'like', 'redemption:'.$redemption->idempotency_key.':%')->get()->all();

            $this->ledger->refund($entries, 'refund:'.$redemption->uuid, $note);
            $redemption->forceFill(['status' => $cancelled ? 'cancelled' : 'failed', 'note' => $note])->save();
        });

        return $redemption;
    }
}
