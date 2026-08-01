<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A MechanicDeployment records that a specific team is using a specific
 * catalog mechanic (wiki.md §4 "Deployment"). This table IS team-scoped via
 * `team_id` — unlike the global `mechanics` catalog, usage is inherently
 * per-team data.
 *
 * MVP scope note: this table intentionally stops at "who is using what,
 * since when, retired when". The full paired engagement/outcome ledger
 * (wiki.md §3 "Wellbeing & Outcome Ledger", §4 "Mechanic outcome") and the
 * cross-platform Knowledge Pack ingestion pipeline are out of scope for this
 * pass — see wiki.md Roadmap and Open Questions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mechanic_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active', 'retired'])->default('active');
            $table->timestamp('started_at');
            $table->timestamp('retired_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_deployments');
    }
};
