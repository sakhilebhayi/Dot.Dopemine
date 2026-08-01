<?php

namespace App\Enums;

/**
 * Lifecycle of a catalog entry (wiki.md §3, §5).
 *
 * A mechanic starts life as Proposed. It can only move to Certified through
 * App\Actions\Dopemine\CertifyMechanic, which enforces the acid-test gate.
 * It can only move to Decertified through App\Actions\Dopemine\DecertifyMechanic.
 * Only Certified mechanics may be deployed to a team (see MechanicDeployment).
 */
enum MechanicStatus: string
{
    case Proposed = 'proposed';
    case Certified = 'certified';
    case Decertified = 'decertified';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::Certified => 'Certified',
            self::Decertified => 'Decertified',
        };
    }
}
