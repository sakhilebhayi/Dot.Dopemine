<?php

return [
    /*
    | Server-to-server intake of contribution events (Dot.Dopemine is the home of the Dot Points ledger).
    | One secret per platform, never shared between platforms; a platform without a secret is refused.
    | The caller signs `{timestamp}.{raw body}` with HMAC-SHA256, the same scheme as Dot.Notify and Dot.Analytics.
    */
    'ingest' => [
        'tolerance' => (int) env('POINTS_INGEST_TOLERANCE', 300),
        'secrets' => [
            'infodot' => env('DOPEMINE_INGEST_SECRET_INFODOT'),
        ],
    ],

    // Company-only knowledge is visible to a handful of colleagues, so it is not rewarded: nobody outside can check it,
    // which is how a second account would farm points. Switch on only with the owner's agreement.
    'reward_company_visibility' => (bool) env('POINTS_REWARD_COMPANY_VISIBILITY', false),

    /*
    | The single heads-up before a lot lapses, delivered through Dot.Notify (see App\Services\Points\ExpiryWarnings).
    | A failed delivery is retried with a growing wait; after `max_attempts` it is given up on and left visible.
    */
    'expiry_warning' => [
        'days_before' => (int) env('POINTS_EXPIRY_WARNING_DAYS', 30),
        'max_attempts' => 6,
        'backoff_minutes' => [60, 120, 240, 480, 960],
        'batch' => 200,
        // Stop a run after this many transient failures in a row: Notify is down, the next run will try again.
        'stop_after_failures' => 3,
    ],

    // Points are worth nothing in cash and cannot be transferred; they lapse this many months after they are granted.
    'lifetime_months' => 12,
];
