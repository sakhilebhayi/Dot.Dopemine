<?php

namespace App\Enums;

/**
 * The fixed, exhaustive allow-list of ethical engagement categories a
 * Mechanic may belong to (wiki.md §2, §6; Dot.Brain platforms/dot-dopemine.md §7).
 *
 * This is the structural half of the ethics gate: because Mechanic::$category
 * is cast to this backed enum, the database column can only ever hold one of
 * these values. There is no free-text category field anywhere in the catalog,
 * so a type like "loss-framed streak" or "FOMO nudge" cannot be created —
 * not because a validator rejects the string, but because no such case
 * exists to construct. Every case here is gain-framed: it describes what the
 * mechanic helps a person move toward, never what they stand to lose.
 *
 * Adding a case is a code change reviewed like any other; it is deliberately
 * not editable at runtime (contrast with the ProhibitedMetricPattern table,
 * which documents patterns rejected by name for people auditing the catalog).
 */
enum MechanicCategory: string
{
    case Progress = 'progress';
    case Achievement = 'achievement';
    case Mastery = 'mastery';
    case Community = 'community';
    case Momentum = 'momentum';
    case Purpose = 'purpose';
    case Learning = 'learning';
    case Confidence = 'confidence';

    public function label(): string
    {
        return match ($this) {
            self::Progress => 'Progress',
            self::Achievement => 'Achievement',
            self::Mastery => 'Mastery',
            self::Community => 'Community',
            self::Momentum => 'Momentum',
            self::Purpose => 'Purpose',
            self::Learning => 'Learning',
            self::Confidence => 'Confidence',
        };
    }

    /**
     * A one-line, gain-framed description of the intent this category
     * certifies — shown next to the category on the catalog dashboard so the
     * ethical framing is visible, not just enforced.
     */
    public function intent(): string
    {
        return match ($this) {
            self::Progress => 'Shows real movement toward a goal that already exists — never manufactures a goal to keep someone moving.',
            self::Achievement => 'Recognizes something genuinely completed or earned.',
            self::Mastery => 'Reflects skill or capability growth over time.',
            self::Community => 'Surfaces genuine recognition from other people, not algorithmic engagement bait.',
            self::Momentum => 'Reinforces a gain already made — framed as what was built, never what will be lost.',
            self::Purpose => 'Connects an action back to the reason the person is doing it.',
            self::Learning => 'Marks understanding or knowledge gained.',
            self::Confidence => 'Builds a person or team\'s belief in their own ability.',
        };
    }
}
