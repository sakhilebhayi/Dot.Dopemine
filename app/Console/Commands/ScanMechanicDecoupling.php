<?php

namespace App\Console\Commands;

use App\Models\Mechanic;
use App\Models\MechanicOutcome;
use App\Models\MechanicRetirementCandidate;
use Illuminate\Console\Command;

/**
 * This platform's first scheduled job (Dot.Brain audit's Level 1/2
 * candidate, wiki.md §11 `engagement.outcome_coupling_rate`).
 *
 * Level 1 (no mutation to certification): for every certified mechanic,
 * compute coupling_rate from its MechanicOutcome records over the last 3
 * months and write it back onto the Mechanic row as pure reporting output.
 *
 * Level 2 (proposal only, never auto-executes): if the mechanic has at
 * least 2 outcome records in the window and coupling_rate < 0.5, raise or
 * refresh an open MechanicRetirementCandidate for a canGovern() admin to
 * review (see App\Livewire\MechanicCatalog::confirmRetirementCandidate /
 * ::dismissRetirementCandidate).
 *
 * Runs without an authenticated user (console context), so
 * MechanicDeployment::HasTeamScope's global scope adds no where('team_id')
 * clause here — this command intentionally sees every team's deployments,
 * since a mechanic's coupling rate spans its use across the whole catalog,
 * not one team's usage of it.
 */
class ScanMechanicDecoupling extends Command
{
    protected $signature = 'dopemine:scan-decoupling';

    protected $description = 'Compute engagement.outcome_coupling_rate per certified mechanic and flag decoupling for admin review.';

    private const WINDOW_MONTHS = 3;

    private const DECOUPLING_THRESHOLD = 0.5;

    private const MINIMUM_SAMPLE_SIZE = 2;

    public function handle(): int
    {
        Mechanic::where('status', 'certified')->each(function (Mechanic $mechanic): void {
            $deploymentIds = $mechanic->activeDeployments()->pluck('id');

            $outcomes = MechanicOutcome::whereIn('deployment_id', $deploymentIds)
                ->where('period_end', '>=', now()->subMonths(self::WINDOW_MONTHS))
                ->get();

            if ($outcomes->isEmpty()) {
                $mechanic->forceFill([
                    'coupling_rate' => null,
                    'coupling_rate_computed_at' => null,
                ])->save();

                return;
            }

            $coupledCount = $outcomes->filter(function (MechanicOutcome $outcome): bool {
                return ! ($outcome->engagement_movement > 0 && $outcome->outcome_movement <= 0);
            })->count();

            $couplingRate = round($coupledCount / $outcomes->count(), 4);

            $mechanic->forceFill([
                'coupling_rate' => $couplingRate,
                'coupling_rate_computed_at' => now(),
            ])->save();

            $sampleSize = $outcomes->count();

            if ($sampleSize < self::MINIMUM_SAMPLE_SIZE || $couplingRate >= self::DECOUPLING_THRESHOLD) {
                return;
            }

            $openCandidate = MechanicRetirementCandidate::where('mechanic_id', $mechanic->id)
                ->where('status', 'open')
                ->first();

            if ($openCandidate) {
                $openCandidate->update([
                    'coupling_rate' => $couplingRate,
                    'sample_size' => $sampleSize,
                ]);

                return;
            }

            MechanicRetirementCandidate::create([
                'mechanic_id' => $mechanic->id,
                'coupling_rate' => $couplingRate,
                'sample_size' => $sampleSize,
                'status' => 'open',
                'detected_at' => now(),
            ]);
        });

        $this->info('Decoupling scan complete.');

        return self::SUCCESS;
    }
}
