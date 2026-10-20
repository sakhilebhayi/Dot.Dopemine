<?php

namespace Database\Seeders;

use App\Models\RewardRule;
use Illuminate\Database\Seeder;

/**
 * The owner's starting schedule (2026-10-19) for InfoDot contributions. These are only the first rows: every
 * value is editable data (RewardRule::revise writes the next version), nothing here is a constant in code.
 * Safe to run again: a rule that already exists is left alone.
 */
class RewardRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // key => [event_type, points, min, max, requires_approval, daily_cap, note]
            'infodot.question.published' => ['infodot.question.published', 2, null, null, true, 6, 'Approved, useful question. Asking is kept cheap on purpose: beginners are not penalised.'],
            'infodot.answer.published' => ['infodot.answer.published', 5, null, null, false, 25, 'Substantive, original answer.'],
            'infodot.answer.accepted' => ['infodot.answer.accepted', 10, null, null, false, null, 'Answer accepted as the solution by the asker or a moderator who is not the answerer.'],
            'infodot.solution.verified' => ['infodot.solution.verified', 10, null, null, false, null, 'Solution independently verified by someone who is neither its author nor the acceptor.'],
            'infodot.tutorial.approved' => ['infodot.tutorial.approved', 10, 10, 20, true, null, 'Approved tutorial or technical guide.'],
            'infodot.troubleshooting.approved' => ['infodot.troubleshooting.approved', 10, 10, 25, true, null, 'Approved reusable troubleshooting solution.'],
            'infodot.contribution.helpful' => ['infodot.contribution.helpful', 1, 1, 3, false, 6, 'Helpful contribution recognised by the community; capped so votes cannot be farmed.'],
            'infodot.knowledge.corrected' => ['infodot.knowledge.corrected', 3, 3, 10, true, null, 'Verified correction to existing knowledge.'],
            'infodot.knowledge.adopted' => ['infodot.knowledge.adopted', 10, 10, 25, true, null, 'Contribution adopted into an approved knowledge pack.'],
            'infodot.solution.multi_user_bonus' => ['infodot.contribution.helpful', 5, 5, 20, true, null, 'High-value solution that helped several people (bonus).'],
        ];

        foreach ($rules as $key => [$event, $points, $min, $max, $approval, $cap, $note]) {
            RewardRule::firstOrCreate(['key' => $key, 'version' => 1], [
                'platform' => 'infodot',
                'event_type' => $event,
                'points' => $points,
                'min_points' => $min,
                'max_points' => $max,
                'requires_approval' => $approval,
                'daily_cap_points' => $cap,
                'max_awards_per_contribution' => 1,
                'active' => true,
                'effective_from' => now(),
                'changed_by' => 'seed',
                'note' => $note,
            ]);
        }
    }
}
