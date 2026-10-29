<?php

namespace App\Services\Points;

/**
 * The server-side gate for `infodot.seminar.hosted` (owner decision 2026-10-29: only the HOST of a seminar earns, and
 * only when it finished with enough distinct attendees and a good enough average rating from enough ratings).
 *
 * InfoDot is expected to send the event only for a seminar that qualifies, but nothing here trusts that: the shape of
 * the event and the thresholds are checked again, and a refusal means a 422 with a reason code, no award, no ledger row
 * and no remembered event id.
 *
 * Strictness: for this one type the event may carry ONLY the standard envelope fields and, inside `facts`, exactly
 * attendee_count, rating_count and average_rating. Anything else (a list of attendees, free text, points, an approval
 * flag) is refused rather than ignored, so identities and content cannot arrive by accident. The other event types keep
 * ignoring facts they do not read.
 */
final class SeminarHostedGate
{
    public const TYPE = 'infodot.seminar.hosted';

    public const RULE_KEY = 'seminar_hosted';

    public const REF_PATTERN = '/^seminar:[A-Za-z0-9._-]{1,100}$/';

    /** @var list<string> */
    public const ENVELOPE_FIELDS = ['event_id', 'type', 'occurred_at', 'user_uuid', 'contribution_ref', 'actor_uuid', 'tenant_uuid', 'visibility', 'facts'];

    /** @var list<string> */
    public const FACT_FIELDS = ['attendee_count', 'rating_count', 'average_rating'];

    /**
     * Why the event cannot be accepted, or null when it can.
     *
     * @param  array<string, mixed>  $payload  the event as received (envelope plus `facts`)
     * @return array{code: string, message: string, fields?: list<string>, reasons?: list<string>}|null
     */
    public static function refusal(array $payload): ?array
    {
        $ref = $payload['contribution_ref'] ?? null;
        if (! is_string($ref) || preg_match(self::REF_PATTERN, $ref) !== 1) {
            return ['code' => 'seminar_ref_invalid', 'message' => 'contribution_ref must be seminar:{id}.'];
        }

        $facts = $payload['facts'] ?? null;
        $facts = is_array($facts) ? $facts : [];

        $unknown = array_merge(
            array_map(fn ($key) => (string) $key, array_values(array_diff(array_keys($payload), self::ENVELOPE_FIELDS))),
            array_map(fn ($key) => 'facts.'.$key, array_values(array_diff(array_map('strval', array_keys($facts)), self::FACT_FIELDS))),
        );
        if ($unknown !== []) {
            return ['code' => 'seminar_unknown_field', 'message' => 'This event type accepts only attendee_count, rating_count and average_rating in facts, and nothing else.', 'fields' => $unknown];
        }

        $invalid = [];
        foreach (['attendee_count', 'rating_count'] as $field) {
            if (! isset($facts[$field]) || ! is_int($facts[$field]) || $facts[$field] < 0) {
                $invalid[] = "facts.{$field} must be an integer of 0 or more";
            }
        }
        if (! isset($facts['average_rating']) || ! self::isRating($facts['average_rating'])) {
            $invalid[] = 'facts.average_rating must be a number from 0 to 5 with at most one decimal';
        }
        if ($invalid !== []) {
            return ['code' => 'seminar_facts_invalid', 'message' => 'The seminar figures are missing or malformed.', 'reasons' => $invalid];
        }

        $cfg = (array) config('points.seminar_hosted');
        $codes = [];
        $reasons = [];
        if ($facts['attendee_count'] < (int) $cfg['min_attendees']) {
            $codes[] = 'seminar_below_attendee_threshold';
            $reasons[] = "at least {$cfg['min_attendees']} distinct attendees are needed";
        }
        if ($facts['rating_count'] < (int) $cfg['min_ratings']) {
            $codes[] = 'seminar_below_rating_count_threshold';
            $reasons[] = "at least {$cfg['min_ratings']} ratings are needed";
        }
        if ((float) $facts['average_rating'] < (float) $cfg['min_average_rating']) {
            $codes[] = 'seminar_below_rating_threshold';
            $reasons[] = 'the average rating must be at least '.number_format((float) $cfg['min_average_rating'], 1);
        }

        return $codes === [] ? null : ['code' => $codes[0], 'message' => 'The seminar does not qualify for points.', 'reasons' => $reasons];
    }

    private static function isRating(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        return $value >= 0 && $value <= 5 && abs($value * 10 - round($value * 10)) < 1e-6;
    }
}
