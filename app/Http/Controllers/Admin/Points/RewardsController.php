<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAudit;
use App\Models\Reward;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The price list. Only things points can buy are listed (recognition is never for sale), a reward is retired rather
 * than deleted so redemptions that point at it keep their meaning, and every change carries a reason and is audited.
 */
class RewardsController extends Controller
{
    public function index(): View
    {
        $pending = DB::table('redemptions')->where('status', 'pending')->selectRaw('reward_id, count(*) as n')->groupBy('reward_id')->pluck('n', 'reward_id');

        return view('admin.points.rewards', [
            'rewards' => Reward::orderByDesc('active')->orderBy('kind')->orderBy('title')->get(),
            'pending' => $pending,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('rewards', 'key')],
            ...$this->fields(),
        ], ['key.regex' => 'Use lower-case letters, numbers, dots, dashes and underscores, e.g. billing.credit-r50.']);

        $reward = DB::transaction(function () use ($data, $request): Reward {
            $reward = Reward::create($this->attributes($data) + ['key' => $data['key'], 'active' => true]);
            AdminAudit::record($request->user(), 'reward.created', 'reward', $reward->key, $data['reason'], $this->snapshot($reward));

            return $reward;
        });

        return redirect()->route('admin.points.rewards')->with('status', "Added “{$reward->title}” to the price list.");
    }

    public function update(Request $request, Reward $reward): RedirectResponse
    {
        $data = $request->validate($this->fields());

        DB::transaction(function () use ($data, $request, $reward): void {
            $before = $this->snapshot($reward);
            $reward->update($this->attributes($data));
            AdminAudit::record($request->user(), 'reward.updated', 'reward', $reward->key, $data['reason'], ['before' => $before, 'after' => $this->snapshot($reward->refresh())]);
        });

        return redirect()->route('admin.points.rewards')->with('status', "Saved “{$reward->title}”. Redemptions already made keep the price they were made at.");
    }

    public function retire(Request $request, Reward $reward): RedirectResponse
    {
        return $this->setActive($request, $reward, false);
    }

    public function reinstate(Request $request, Reward $reward): RedirectResponse
    {
        return $this->setActive($request, $reward, true);
    }

    private function setActive(Request $request, Reward $reward, bool $active): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($reward->active === $active) {
            return redirect()->route('admin.points.rewards')->with('status', "“{$reward->title}” was already ".($active ? 'on sale' : 'retired').'.');
        }

        DB::transaction(function () use ($request, $reward, $active, $data): void {
            $reward->update(['active' => $active]);
            AdminAudit::record($request->user(), $active ? 'reward.reinstated' : 'reward.retired', 'reward', $reward->key, $data['reason']);
        });

        return redirect()->route('admin.points.rewards')->with('status', $active ? "“{$reward->title}” is on sale again." : "“{$reward->title}” is retired: nobody can redeem it, and pending redemptions are unaffected.");
    }

    /** @return array<string, array<int, mixed>> */
    private function fields(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(Reward::KINDS)],
            'price_points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'monthly_cap' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'kind' => $data['kind'],
            'price_points' => (int) $data['price_points'],
            'stock' => isset($data['stock']) && $data['stock'] !== '' ? (int) $data['stock'] : null,
            'monthly_cap' => isset($data['monthly_cap']) && $data['monthly_cap'] !== '' ? (int) $data['monthly_cap'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Reward $reward): array
    {
        return $reward->only(['title', 'description', 'kind', 'price_points', 'stock', 'monthly_cap', 'active']);
    }
}
