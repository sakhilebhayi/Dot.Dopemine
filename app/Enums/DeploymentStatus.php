<?php

namespace App\Enums;

/**
 * Lifecycle of a single team's use of a certified mechanic (wiki.md §4, §5).
 */
enum DeploymentStatus: string
{
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Retired => 'Retired',
        };
    }
}
