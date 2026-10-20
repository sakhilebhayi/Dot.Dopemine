<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishRewardRuleRequest;
use App\Models\AdminAudit;
use App\Models\RewardRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/** Reward rules and their version history. A rule is never edited: publishing writes the next version. */
class RewardRulesController extends Controller
{
    public function index(): View
    {
        $versions = RewardRule::orderBy('key')->orderByDesc('version')->get()->groupBy('key');

        return view('admin.points.rules', ['rules' => $versions]);
    }

    public function publish(PublishRewardRuleRequest $request, string $key): RedirectResponse
    {
        $admin = $request->user();
        $latest = $request->latest();
        $changes = $request->changes();

        try {
            $next = DB::transaction(function () use ($admin, $latest, $changes, $request, $key): RewardRule {
                $next = RewardRule::revise($key, $request->newValues(), $admin->name.' (#'.$admin->id.')', (string) $request->input('reason'));

                AdminAudit::record($admin, 'rule.published', 'reward_rule', $key, (string) $request->input('reason'), [
                    'from_version' => $latest->version,
                    'to_version' => $next->version,
                    'changed' => collect($changes)->map(fn ($to, $field) => ['from' => $latest->{$field}, 'to' => $to])->all(),
                ]);

                return $next;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['based_on_version' => 'Another version was published at the same moment. Review it and try again.'], PublishRewardRuleRequest::errorBag($key));
        }

        return redirect()->to(route('admin.points.rules').'#rule-'.str_replace('.', '-', $key))
            ->with('status', "Published {$key} version {$next->version}. Points awarded from now on use it; points already awarded keep the version that produced them.");
    }
}
