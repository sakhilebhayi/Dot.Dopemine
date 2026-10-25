<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAudit;
use App\Models\Redemption;
use App\Services\Points\RewardRedemptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Redemptions waiting for a person to deliver them (billing credit, capacity and marketplace items are fulfilled
 * by hand until Dot.Billing and the marketplace have hooks). Fulfilling closes the request; failing or cancelling
 * gives the points back through RewardRedemptions, which owns the ledger write. Pressing a button twice is safe:
 * a repeat of the same decision does nothing, and a contradicting one is refused.
 */
class RedemptionsController extends Controller
{
    public function index(): View
    {
        return view('admin.points.redemptions', [
            'pending' => Redemption::with('reward')->where('status', 'pending')->orderBy('created_at')->orderBy('id')->get(),
            'resolved' => Redemption::with('reward')->where('status', '!=', 'pending')->orderByDesc('updated_at')->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    public function fulfil(Request $request, Redemption $redemption, RewardRedemptions $redemptions): RedirectResponse
    {
        $data = $request->validate(['fulfilment_ref' => ['required', 'string', 'min:2', 'max:191']], [
            'fulfilment_ref.required' => 'Enter the reference of what was delivered (an invoice, credit note or order number).',
        ]);

        if ($redemption->status === 'fulfilled') {
            return $this->back('This redemption was already marked fulfilled. Nothing changed.');
        }

        if ($redemption->status !== 'pending') {
            return $this->back("This redemption is already {$redemption->status}; it cannot be fulfilled.", error: true);
        }

        $admin = $request->user();

        try {
            DB::transaction(function () use ($redemptions, $redemption, $data, $admin): void {
                $redemptions->fulfil($redemption, $data['fulfilment_ref'], 'admin#'.$admin->id);
                AdminAudit::record($admin, 'redemption.fulfilled', 'redemption', $redemption->uuid, 'Fulfilled: '.$data['fulfilment_ref'], ['points' => $redemption->points, 'reward_id' => $redemption->reward_id]);
            });
        } catch (HttpException) {
            return $this->settled($redemption->refresh(), 'fulfilled');
        }

        return $this->back('Marked fulfilled.');
    }

    public function fail(Request $request, Redemption $redemption, RewardRedemptions $redemptions): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:failed,cancelled'],
            'note' => ['required', 'string', 'min:5', 'max:300'],
        ], ['note.required' => 'Say why. The person will see this reason next to their refunded points.']);

        if ($redemption->status === $data['outcome']) {
            return $this->back("This redemption was already marked {$data['outcome']}. Nothing changed, and the points were not returned twice.");
        }

        if ($redemption->status !== 'pending') {
            return $this->back("This redemption is already {$redemption->status}; it cannot be marked {$data['outcome']}.", error: true);
        }

        $admin = $request->user();

        try {
            DB::transaction(function () use ($redemptions, $redemption, $data, $admin): void {
                $redemptions->fail($redemption, $data['note'], $data['outcome'] === 'cancelled');
                AdminAudit::record($admin, 'redemption.'.$data['outcome'], 'redemption', $redemption->uuid, $data['note'], ['points_returned' => $redemption->points, 'reward_id' => $redemption->reward_id]);
            });
        } catch (HttpException) {
            return $this->settled($redemption->refresh(), $data['outcome']);
        }

        return $this->back("Marked {$data['outcome']}. {$redemption->points} points went back to the person as a new lot.");
    }

    /** Someone else decided between our check and our write: report what stands. */
    private function settled(Redemption $redemption, string $wanted): RedirectResponse
    {
        return $redemption->status === $wanted
            ? $this->back("This redemption was already marked {$wanted}. Nothing changed.")
            : $this->back("This redemption is now {$redemption->status}, so it was not marked {$wanted}.", error: true);
    }

    private function back(string $message, bool $error = false): RedirectResponse
    {
        return redirect()->route('admin.points.redemptions')->with($error ? 'error' : 'status', $message);
    }
}
