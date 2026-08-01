<?php

namespace Database\Seeders;

use App\Models\Mechanic;
use App\Models\ProhibitedMetricPattern;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the Mechanic Catalog with example entries drawn from wiki.md's
 * architecture blueprint, and the prohibited-metric list from wiki.md §6 /
 * Dot.Brain platforms/dot-dopemine.md §7.
 *
 * Every mechanic below is gain-framed: it describes progress already made,
 * a genuine achievement, a skill gained, or real recognition from other
 * people — never a loss to avoid, an artificial deadline, or a randomized
 * reward. Compare "consecutive days of forward progress" (seeded, certified)
 * against "don't break your streak" (impossible to model — no loss-framed
 * category exists in App\Enums\MechanicCategory to assign it).
 */
class MechanicCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $officer = User::first();

        $mechanics = [
            [
                'key' => 'milestone-celebration',
                'name' => 'Milestone Celebration',
                'description' => 'Marks a genuine, pre-existing milestone (project phase complete, first sale, N tasks shipped) with a one-time in-product moment. Never repeats artificially and never counts down to create urgency.',
                'category' => 'achievement',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Shown to a project lead who just finished a phase: yes, without hesitation — it recognizes something real that already happened.',
            ],
            [
                'key' => 'mastery-progress-bar',
                'name' => 'Mastery Progress Bar',
                'description' => 'Visualizes skill or capability growth toward a defined competency (e.g. onboarding checklist, certification track). The bar only ever reflects verified capability, never time-in-app.',
                'category' => 'mastery',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Intent label reads "63% toward Certified Operator" — states the outcome, not engagement.',
            ],
            [
                'key' => 'peer-recognition-wall',
                'name' => 'Peer Recognition Wall',
                'description' => 'Surfaces genuine, opt-in recognition from teammates (kudos, shoutouts) tied to a specific contribution. Not a leaderboard — no ranking, no rate comparison between people.',
                'category' => 'community',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Every entry names the specific contribution being recognized; nothing is auto-generated from activity volume.',
            ],
            [
                'key' => 'forward-progress-count',
                'name' => 'Forward Progress Count',
                'description' => 'Counts consecutive periods of genuine forward movement on a goal (not logins, not opens). Framed entirely as what has been built so far — breaking the count costs nothing and is never flagged, warned against, or visually punished.',
                'category' => 'momentum',
                'acid_test_passed' => true,
                'acid_test_notes' => 'This is the gain-framed counterpart to a loss-framed streak — see wiki.md §6 and the incident this platform already decertified once (Dot.Brain platforms/dot-dopemine.md §10). No "don\'t lose your streak" copy exists anywhere in this mechanic\'s definition.',
            ],
            [
                'key' => 'goal-to-purpose-link',
                'name' => 'Goal-to-Purpose Link',
                'description' => 'Lets a person or team attach a short "why" to a goal, then reflects that reason back at completion time ("You finished this because: launch the community garden program").',
                'category' => 'purpose',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Purely reflective — no notification, no reminder cadence tied to it.',
            ],
            [
                'key' => 'skill-unlock-map',
                'name' => 'Skill Unlock Map',
                'description' => 'A visual map of capabilities gained through real usage (e.g. "ran your first automated report"), unlocked once, never regressed or reset.',
                'category' => 'learning',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Unlocks are one-way and permanent — nothing to lose, nothing to defend.',
            ],
            [
                'key' => 'confidence-checkpoint',
                'name' => 'Confidence Checkpoint',
                'description' => 'A short, optional self-assessment after a completed task ("How confident do you feel doing this again?") that a person can track improving over time — private by default, shareable only if the person chooses.',
                'category' => 'confidence',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Opt-in, private by default, and never used to rank people against each other.',
            ],
            [
                'key' => 'business-growth-tracker',
                'name' => 'Business Growth Tracker',
                'description' => 'Tracks a team-defined business outcome (revenue, customers served, deals closed) against its own trend, celebrating real growth without comparing teams against each other.',
                'category' => 'progress',
                'acid_test_passed' => true,
                'acid_test_notes' => 'Compares a team only to its own history, never to another team on a rate metric (wiki.md §6 "person-vs-person leaderboards on rate metrics").',
            ],
        ];

        foreach ($mechanics as $mechanic) {
            Mechanic::updateOrCreate(
                ['key' => $mechanic['key']],
                array_merge($mechanic, [
                    'status' => 'certified',
                    'certified_by' => $officer?->id,
                    'certified_at' => now(),
                ])
            );
        }

        $prohibited = [
            [
                'pattern' => 'Raw engagement volume as a target (dwell time, session count, opens)',
                'reason' => 'Rewards attention captured, not outcomes achieved.',
                'example' => 'Emall browse-time; Analytics dashboard-view rankings.',
            ],
            [
                'pattern' => 'Individual streaks with loss framing',
                'reason' => 'Manufactures compulsion via loss aversion rather than progress. See the Forward Progress Count mechanic above for the gain-framed alternative this platform actually certifies.',
                'example' => 'Dot.Memory\'s documented lesson: loss-framed uptime streaks made people hide problems rather than report them.',
            ],
            [
                'pattern' => 'Person-vs-person leaderboards on rate metrics',
                'reason' => 'Rewards speed over safety and quality.',
                'example' => 'Mines operator speed leaderboards.',
            ],
            [
                'pattern' => 'Variable-ratio reward schedules',
                'reason' => 'Slot-machine mechanics — never deployed, blocked at catalog by the fixed MechanicCategory enum having no "randomized reward" case.',
                'example' => 'None deployed; blocked structurally.',
            ],
            [
                'pattern' => 'Abandonment/re-engagement pressure nudges',
                'reason' => 'Exploits incompleteness anxiety instead of genuine value.',
                'example' => 'Emall cart-abandonment nudges; Billing dunning-pressure notifications.',
            ],
        ];

        foreach ($prohibited as $entry) {
            ProhibitedMetricPattern::updateOrCreate(['pattern' => $entry['pattern']], $entry);
        }
    }
}
