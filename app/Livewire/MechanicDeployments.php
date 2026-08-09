<?php

namespace App\Livewire;

use App\Actions\Dopemine\RetireMechanicDeployment;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Which certified mechanics the current team has deployed, and their
 * status. Deploying happens from MechanicCatalog; this component covers
 * viewing, retiring (wiki.md §5 `engagement.deployment.retired`), and
 * recording paired engagement/outcome movement (wiki.md §4 "Mechanic
 * outcome") — the deploying team self-reports its own metrics, matching
 * wiki.md §3's architecture diagram.
 *
 * MechanicDeployment::HasTeamScope already restricts every query below to
 * the current team, so no explicit where('team_id', ...) is needed here —
 * a cross-team ID passed to retire() or startRecordingOutcome() is
 * invisible to the model and findOrFail() throws ModelNotFoundException,
 * same as before.
 */
class MechanicDeployments extends Component
{
    public ?int $recordingOutcomeId = null;

    public string $outcomePeriodStart = '';

    public string $outcomePeriodEnd = '';

    public string $outcomeEngagementMovement = '';

    public string $outcomeOutcomeMovement = '';

    public string $outcomeNotes = '';

    #[Computed]
    public function deployments(): Collection
    {
        return MechanicDeployment::query()
            ->with('mechanic')
            ->latest('started_at')
            ->get();
    }

    public function retire(int $deploymentId): void
    {
        $deployment = MechanicDeployment::findOrFail($deploymentId);

        app(RetireMechanicDeployment::class)->retire($deployment);

        unset($this->deployments);
    }

    public function startRecordingOutcome(int $deploymentId): void
    {
        // Scoped by HasTeamScope — throws ModelNotFoundException for a
        // cross-team ID before any form state is set.
        MechanicDeployment::findOrFail($deploymentId);

        $this->recordingOutcomeId = $deploymentId;
        $this->outcomePeriodStart = '';
        $this->outcomePeriodEnd = '';
        $this->outcomeEngagementMovement = '';
        $this->outcomeOutcomeMovement = '';
        $this->outcomeNotes = '';
    }

    public function cancelRecordingOutcome(): void
    {
        $this->recordingOutcomeId = null;
    }

    public function saveOutcome(): void
    {
        $deployment = MechanicDeployment::findOrFail($this->recordingOutcomeId);

        $this->validate([
            'outcomePeriodStart' => ['required', 'date'],
            'outcomePeriodEnd' => ['required', 'date', 'after_or_equal:outcomePeriodStart'],
            'outcomeEngagementMovement' => ['required', 'numeric'],
            'outcomeOutcomeMovement' => ['required', 'numeric'],
            'outcomeNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => $this->outcomePeriodStart,
            'period_end' => $this->outcomePeriodEnd,
            'engagement_movement' => $this->outcomeEngagementMovement,
            'outcome_movement' => $this->outcomeOutcomeMovement,
            'recorded_by' => auth()->id(),
            'notes' => $this->outcomeNotes ?: null,
        ]);

        $this->recordingOutcomeId = null;
    }

    public function render()
    {
        return view('livewire.mechanic-deployments');
    }
}
