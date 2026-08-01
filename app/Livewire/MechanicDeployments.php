<?php

namespace App\Livewire;

use App\Actions\Dopemine\RetireMechanicDeployment;
use App\Models\MechanicDeployment;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Which certified mechanics the current team has deployed, and their
 * status. Deploying happens from MechanicCatalog; this component covers
 * viewing and retiring (wiki.md §5 `engagement.deployment.retired`).
 */
class MechanicDeployments extends Component
{
    #[Computed]
    public function deployments(): Collection
    {
        return MechanicDeployment::query()
            ->where('team_id', auth()->user()->currentTeam->id)
            ->with('mechanic')
            ->latest('started_at')
            ->get();
    }

    public function retire(int $deploymentId): void
    {
        $deployment = MechanicDeployment::where('team_id', auth()->user()->currentTeam->id)
            ->findOrFail($deploymentId);

        app(RetireMechanicDeployment::class)->retire($deployment);

        unset($this->deployments);
    }

    public function render()
    {
        return view('livewire.mechanic-deployments');
    }
}
