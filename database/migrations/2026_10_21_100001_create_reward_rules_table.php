<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reward rules are DATA, not code: values, eligibility, caps and approval requirements are changed by
 * administrators without a deploy. A rule is immutable once written; editing it writes the next `version`,
 * and every ledger entry records the exact version that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->unsignedInteger('version');
            $table->string('platform', 32)->default('infodot');
            $table->string('event_type', 80);
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('min_points')->nullable();
            $table->unsignedInteger('max_points')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->unsignedInteger('daily_cap_points')->nullable();
            $table->unsignedInteger('max_awards_per_contribution')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->string('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['platform', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_rules');
    }
};
