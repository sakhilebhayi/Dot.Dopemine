<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paired engagement/outcome ledger (wiki.md §3 "Wellbeing & Outcome
 * Ledger", §4 "Mechanic outcome", natural key: deployment + period).
 *
 * `engagement_movement` and `outcome_movement` are both NOT NULL — there is
 * no schema-level way to record one without the other. This is the literal
 * implementation of the Roadmap's "reject-at-ingestion for unpaired
 * engagement metrics" (wiki.md §9): the column itself refuses a null, not
 * just a validator that could be bypassed.
 *
 * No team_id of its own: always created/queried through an already
 * team-scoped MechanicDeployment (App\Models\Concerns\HasTeamScope), so a
 * redundant scope column would duplicate authority that already exists one
 * hop away — see app/Livewire/MechanicDeployments.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('mechanic_deployments')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('engagement_movement', 8, 4);
            $table->decimal('outcome_movement', 8, 4);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_outcomes');
    }
};
