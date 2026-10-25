<?php

namespace App\Services\Points;

use App\Models\LedgerEntry;

/** What happened to an award request. Only `awarded` created points. */
final class AwardOutcome
{
    public const AWARDED = 'awarded';

    public const DUPLICATE = 'duplicate';

    public const ALREADY_REWARDED = 'already_rewarded';

    public const DAILY_CAP = 'daily_cap';

    public const RULE_OFF = 'rule_off';

    public const NEEDS_APPROVAL = 'needs_approval';

    public function __construct(public readonly string $status, public readonly ?LedgerEntry $entry = null) {}

    public function awarded(): bool
    {
        return $this->status === self::AWARDED;
    }
}
