<?php

namespace App\Actions\Dopemine;

use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * A team adopts a certified mechanic from the catalog (wiki.md §5
 * `engagement.deployment.started`). Only certified mechanics may be
 * deployed — MechanicDeployment::booted() enforces this a second time at
 * the model layer, but this action gives the caller a clean validation
 * error instead of a raw exception.
 */
class DeployMechanic
{
    public function deploy(Team $team, Mechanic $mechanic, ?string $notes = null): MechanicDeployment
    {
        if (! $mechanic->isCertified()) {
            throw ValidationException::withMessages([
                'mechanic' => "\"{$mechanic->name}\" is not certified and cannot be deployed (status: {$mechanic->status->label()}).",
            ]);
        }

        return MechanicDeployment::create([
            'team_id' => $team->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
            'started_at' => now(),
            'notes' => $notes,
        ]);
    }
}
