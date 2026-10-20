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

    // Points are worth nothing in cash and cannot be transferred; they lapse this many months after they are granted.
    'lifetime_months' => 12,
];
