<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAudit;
use App\Models\Contribution;
use App\Models\RewardRule;
use App\Services\Points\ContributionEventProcessor;
use App\Services\Points\PointsLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contributions waiting for a moderator's approval before their approval-gated reward can be paid. An administrator
 * may approve from here when the moderator's approval never arrived. It runs the same code path as the
 * `infodot.contribution.approved` event (ContributionEventProcessor::approvePending): the same rule, caps,
 * visibility policy and one-payment-per-contribution guard. Pressing Approve twice pays once.
 */
class ApprovalsController extends Controller
{
    public function index(PointsLedger $ledger): View
    {
        $pending = Contribution::awaitingApproval()->orderBy('status_changed_at')->orderBy('id')->get()->map(function (Contribution $contribution) use ($ledger): array {
            $ruleKey = $contribution->pendingRuleKey();
            $rule = $ruleKey ? RewardRule::current($ruleKey) : null;
            $requested = isset($contribution->meta['pending_points']) ? (int) $contribution->meta['pending_points'] : null;

            return [
                'contribution' => $contribution,
                'rule_key' => $ruleKey,
                'rule_on' => $rule !== null,
                'amount' => $rule ? $ledger->amountFor($rule, $requested) : null,
                'unrewarded' => $contribution->visibility !== 'public' && ! config('points.reward_company_visibility'),
                'since' => $contribution->status_changed_at ?? $contribution->created_at,
            ];
        });

        return view('admin.points.approvals', ['pending' => $pending]);
    }

    public function approve(Request $request, Contribution $contribution, ContributionEventProcessor $processor): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $admin = $request->user();
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Approved by an administrator.';

        $result = DB::transaction(function () use ($processor, $contribution, $admin, $reason): array {
            // A back-office administrator has no InfoDot identity, so the approver is recorded as `admin:<id>`: it
            // can never equal an author's uuid, and the processor refuses it if it ever did.
            $result = $processor->approvePending($contribution, 'admin:'.$admin->id, 'admin-approve:'.$contribution->uuid, 'admin');

            if ($result['outcome'] === 'awarded') {
                AdminAudit::record($admin, 'contribution.approved', 'contribution', $contribution->ref, $reason, [
                    'author' => $contribution->user_uuid,
                    'rule_key' => $contribution->pendingRuleKey(),
                    'points' => $result['points'] ?? null,
                ]);
            }

            return $result;
        });

        return match ($result['outcome']) {
            'awarded' => $this->back("Approved {$contribution->ref}. {$result['points']} points were paid to the author."),
            'already_approved' => $this->back("{$contribution->ref} was already approved. Nothing changed, and nothing was paid twice."),
            'duplicate' => $this->back("{$contribution->ref} was already approved. Nothing changed."),
            default => $this->back("{$contribution->ref} was not approved: ".($result['detail'] ?? str_replace('_', ' ', $result['outcome'])), error: true),
        };
    }

    private function back(string $message, bool $error = false): RedirectResponse
    {
        return redirect()->route('admin.points.approvals')->with($error ? 'error' : 'status', $message);
    }
}
