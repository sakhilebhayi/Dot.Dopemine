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

            // Stock is claimed atomically (one conditional UPDATE), so two people cannot take the last unit. It sits
            // inside the transaction: if the points turn out to be missing, the claim is rolled back with it.
            $stocked = Reward::whereKey($reward->id)
                ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
                ->update(['stock' => DB::raw('CASE WHEN stock IS NULL THEN NULL ELSE stock - 1 END')]);

            if ($stocked === 0) {
                throw new RedemptionRefused('This reward is out of stock.');
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

    /**
     * Mark a pending redemption delivered. The status change is one conditional UPDATE, so two operators pressing
     * the button at once cannot both win; the loser gets the same 422 as for any other non-pending redemption.
     */
    public function fulfil(Redemption $redemption, string $reference, string $by): Redemption
    {
        $claimed = Redemption::whereKey($redemption->id)->where('status', 'pending')
            ->update(['status' => 'fulfilled', 'fulfilment_ref' => $reference, 'fulfilled_by' => $by, 'updated_at' => now()]);

        abort_unless($claimed === 1, 422, 'Only a pending redemption can be fulfilled.');

        return $redemption->refresh();
    }

    /**
     * A redemption that could not be delivered, or that was cancelled: the points come back as a new lot (a
     * `refund` entry, keyed on the redemption so a repeat can never refund twice) and a unit of limited stock is
     * returned. The status change is the single conditional UPDATE that decides who gets to do this.
     */
    public function fail(Redemption $redemption, string $note, bool $cancelled = false): Redemption
    {
        DB::transaction(function () use ($redemption, $note, $cancelled): void {
            $claimed = Redemption::whereKey($redemption->id)->where('status', 'pending')
                ->update(['status' => $cancelled ? 'cancelled' : 'failed', 'note' => $note, 'updated_at' => now()]);

            abort_unless($claimed === 1, 422, 'Only a pending redemption can be failed or cancelled.');

            $entries = LedgerEntry::where('user_uuid', $redemption->user_uuid)
                ->where('idempotency_key', 'like', 'redemption:'.$redemption->idempotency_key.':%')->get()->all();

            $this->ledger->refund($entries, 'refund:'.$redemption->uuid, $note);

            Reward::whereKey($redemption->reward_id)->whereNotNull('stock')->increment('stock');
        });

        return $redemption->refresh();
    }
}
