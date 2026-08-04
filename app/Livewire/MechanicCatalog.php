<?php

namespace App\Livewire;

use App\Actions\Dopemine\CertifyMechanic;
use App\Actions\Dopemine\DecertifyMechanic;
use App\Actions\Dopemine\DeployMechanic;
use App\Enums\MechanicCategory;
use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Browse the global Mechanic Catalog (wiki.md §3). Any authenticated team
 * member can browse. Certify / decertify is gated behind the current team's
 * `admin` role, standing in for a dedicated Ethics Officer role until real
 * ecosystem-wide governance exists — see wiki.md Open Questions.
 */
class MechanicCatalog extends Component
{
    public ?string $categoryFilter = null;

    public ?string $statusFilter = 'certified';

    public string $decertifyingReason = '';

    public ?int $decertifyingId = null;

    #[Computed]
    public function categories(): array
    {
        return MechanicCategory::cases();
    }

    #[Computed]
    public function statuses(): array
    {
        return MechanicStatus::cases();
    }

    #[Computed]
    public function mechanics(): Collection
    {
        return Mechanic::query()
            ->when($this->categoryFilter, fn ($query) => $query->where('category', $this->categoryFilter))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->withCount(['activeDeployments'])
            ->orderBy('name')
            ->get();
    }

    /**
     * A user's current_team_id can be null even for an existing account —
     * Jetstream's Team::removeUser() (invoked from RemoveTeamMember) nulls
     * it out when a user is removed from whichever team happens to be
     * their current one, and nothing re-points it at their personal team
     * afterward. So `Auth::user()->currentTeam` is genuinely nullable
     * here, not just defensively so.
     */
    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    public function canGovern(): bool
    {
        $user = auth()->user();
        $team = $this->resolveCurrentTeam();

        return $user && $team && $user->hasTeamRole($team, 'admin');
    }

    public function deployToCurrentTeam(int $mechanicId): void
    {
        $team = $this->resolveCurrentTeam();

        // No unscoped fallback here: this action is only reachable from an
        // already-rendered page, so there is no safe redirect target — a
        // user with no active team (e.g. removed from their current team,
        // see resolveCurrentTeam()) simply cannot deploy a mechanic to one.
        abort_if(! $team, 403, 'No active team selected.');

        $mechanic = Mechanic::findOrFail($mechanicId);

        app(DeployMechanic::class)->deploy($team, $mechanic);

        unset($this->mechanics);

        $this->dispatch('mechanic-deployed');
    }

    public function certify(int $mechanicId): void
    {
        abort_unless($this->canGovern(), 403);

        $mechanic = Mechanic::findOrFail($mechanicId);

        app(CertifyMechanic::class)->certify(auth()->user(), $mechanic);

        unset($this->mechanics);
    }

    public function startDecertify(int $mechanicId): void
    {
        abort_unless($this->canGovern(), 403);

        $this->decertifyingId = $mechanicId;
        $this->decertifyingReason = '';
    }

    public function confirmDecertify(): void
    {
        abort_unless($this->canGovern(), 403);

        $this->validate([
            'decertifyingReason' => ['required', 'string', 'max:2000'],
        ]);

        $mechanic = Mechanic::findOrFail($this->decertifyingId);

        app(DecertifyMechanic::class)->decertify(auth()->user(), $mechanic, [
            'reason' => $this->decertifyingReason,
        ]);

        $this->decertifyingId = null;
        $this->decertifyingReason = '';

        unset($this->mechanics);
    }

    public function render()
    {
        return view('livewire.mechanic-catalog');
    }
}
