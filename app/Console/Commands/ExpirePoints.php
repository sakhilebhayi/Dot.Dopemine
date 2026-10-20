<?php

namespace App\Console\Commands;

use App\Services\Points\PointsLedger;
use Illuminate\Console\Command;

/** Writes `expire` ledger entries for points that passed their 12-month life. Safe to run any number of times. */
class ExpirePoints extends Command
{
    protected $signature = 'points:expire';

    protected $description = 'Expire Dot Points lots that are past their 12-month life (idempotent)';

    public function handle(PointsLedger $ledger): int
    {
        $this->info('Expired '.$ledger->expireDue().' lot(s).');

        return self::SUCCESS;
    }
}
