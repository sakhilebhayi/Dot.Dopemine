<?php

namespace App\Actions\Dopemine;

use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Revokes certification (wiki.md §5 `engagement.mechanic.decertified`, §10
 * incident history — a pilot streak mechanic was decertified after a
 * decoupling finding, becoming the evidence behind the loss-framed-streak
 * prohibited-list entry). Decertifying also retires every active deployment
 * of the mechanic, since a deployment may only exist against a certified
 * mechanic (see MechanicDeployment::booted()).
 */
class DecertifyMechanic
{
    /**
     * @param  array{reason: string}  $input
     */
    public function decertify(User $ethicsOfficer, Mechanic $mechanic, array $input): Mechanic
    {
        Validator::make($input, [
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $mechanic->forceFill([
            'status' => MechanicStatus::Decertified,
            'decertification_reason' => $input['reason'],
            'decertified_at' => now(),
        ])->save();

        $mechanic->activeDeployments->each(function ($deployment) {
            $deployment->forceFill([
                'status' => 'retired',
                'retired_at' => now(),
            ])->save();
        });

        return $mechanic->fresh();
    }
}
