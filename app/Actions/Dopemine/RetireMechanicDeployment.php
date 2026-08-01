<?php

namespace App\Actions\Dopemine;

use App\Models\MechanicDeployment;

/**
 * A team stops using a mechanic (wiki.md §5 `engagement.deployment.retired`).
 */
class RetireMechanicDeployment
{
    public function retire(MechanicDeployment $deployment): MechanicDeployment
    {
        $deployment->forceFill([
            'status' => 'retired',
            'retired_at' => now(),
        ])->save();

        return $deployment->fresh();
    }
}
