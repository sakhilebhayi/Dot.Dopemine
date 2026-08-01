<?php

namespace App\Livewire;

use App\Actions\Dopemine\CertifyMechanic;
use App\Actions\Dopemine\DecertifyMechanic;
use App\Actions\Dopemine\DeployMechanic;
use App\Enums\MechanicCategory;
use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use Illuminate\Support\Collection;
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

    public function canGovern(): bool
    {
        $user = auth()->user();

        return $user && $user->currentTeam && $user->hasTeamRole($user->currentTeam, 'admin');
    }

    public function deployToCurrentTeam(int $mechanicId): void
    {
        $mechanic = Mechanic::findOrFail($mechanicId);
        $team = auth()->user()->currentTeam;

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
