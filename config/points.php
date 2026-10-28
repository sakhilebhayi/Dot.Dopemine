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

    /*
    | Diminishing returns for a pair of accounts that reward each other (the collusion safeguard of the community-rewards
    | brief, section 9). Only a RECIPROCAL pair is affected: if A's work was accepted, verified or approved by B, and B's
    | by A, inside `window_days`, every further award between those two pays less. One-way help is never reduced: an asker
    | who keeps accepting an expert's answers does not reduce the expert's points. The first `free_awards` between the pair
    | pay in full; the next ones pay the fractions in `steps` (the last step repeats): 0.5, 0.25, then 0 means "stop paying".
    | Back-office approvals (`admin:...`) are not counted. Every reduction is written to the entry (`meta.pair_decay`).
    */
    'pair_decay' => [
        'enabled' => (bool) env('POINTS_PAIR_DECAY', true),
        'window_days' => (int) env('POINTS_PAIR_DECAY_DAYS', 30),
        'free_awards' => (int) env('POINTS_PAIR_FREE_AWARDS', 3),
        'steps' => [0.5, 0.25, 0.0],
    ],

    // Company-only knowledge is visible to a handful of colleagues, so it is not rewarded: nobody outside can check it,
    // which is how a second account would farm points. Switch on only with the owner's agreement.
    'reward_company_visibility' => (bool) env('POINTS_REWARD_COMPANY_VISIBILITY', false),

    /*
    | How the single expiry heads-up reaches a person (exactly one path is ever active):
    |   infodot  InfoDot PULLS pending warnings (GET /api/points/expiry-warnings) and emails the person itself,
    |            reporting back per warning. Dopemine never holds or receives an address. This is the default.
    |   notify   the scheduled `points:warn-expiring` command pushes through Dot.Notify (needs a recipient lookup).
    |   none     warnings are not delivered by anyone.
    */
    'expiry_delivery' => env('POINTS_EXPIRY_DELIVERY', 'infodot'),

    /*
    | The single heads-up before a lot lapses (see `expiry_delivery` for who delivers it; the Notify path is App\Services\Points\ExpiryWarnings) (see App\Services\Points\ExpiryWarnings).
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

    /*
    | Weekly / monthly summaries of what a person earned (App\Services\Points\DigestPull). InfoDot PULLS them
    | (GET /api/points/digests), composes and sends the message under the person's own preferences and reports back per
    | digest. OFF by default: while `enabled` is false the API answers an empty list and nothing is produced.
    | `max_per_pull` caps how many digests one pull creates and returns (the rest follow on the next pull).
    */
    'digests' => [
        'enabled' => (bool) env('POINTS_DIGESTS_ENABLED', false),
        'max_per_pull' => (int) env('POINTS_DIGEST_MAX_PER_PULL', 200),
        'max_attempts' => 5,
        'backoff_minutes' => [60, 120, 240, 480, 960],
    ],

    // Spending through the platform API: a person can ask to redeem this many times per window (new requests only;
    // replaying an idempotency key is free). Past it the API answers 429 `rate_limited`.
    'redemptions' => [
        'rate_limit' => (int) env('POINTS_REDEMPTION_RATE_LIMIT', 10),
        'rate_window_seconds' => 3600,
    ],

    // Points are worth nothing in cash and cannot be transferred; they lapse this many months after they are granted.
    'lifetime_months' => 12,
];
