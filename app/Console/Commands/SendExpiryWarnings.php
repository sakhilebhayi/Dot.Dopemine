<?php

namespace App\Console\Commands;

use App\Services\Points\ExpiryWarnings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Sends the 30-day "your points are about to lapse" heads-up through Dot.Notify. Safe to run any number of times. */
class SendExpiryWarnings extends Command
{
    protected $signature = 'points:warn-expiring';

    protected $description = 'Warn people (through Dot.Notify) about Dot Points that lapse within 30 days; one message per lot, retried on failure';

    public function handle(ExpiryWarnings $warnings): int
    {
        $mode = (string) config('points.expiry_delivery', 'infodot');

        if ($mode !== 'notify') {
            Log::info('Expiry warnings: the Dot.Notify push is off (POINTS_EXPIRY_DELIVERY='.$mode.'); nothing sent.');
            $this->line('Expiry warning delivery mode is "'.$mode.'"; the Dot.Notify push only runs in "notify" mode.');

            return self::SUCCESS;
        }

        $summary = $warnings->run();

        if (! $summary['configured']) {
            $this->warn('Dot.Notify is not configured (NOTIFY_INGEST_URL / NOTIFY_INGEST_SECRET); no warnings were sent.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Warnings: %d queued, %d delivered, %d to retry, %d failed, %d no longer needed, %d without an address.',
            $summary['queued'], $summary['delivered'], $summary['retrying'], $summary['failed'], $summary['moot'], $summary['unaddressable'],
        ));

        if ($summary['stopped'] !== null) {
            $this->error($summary['stopped']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
