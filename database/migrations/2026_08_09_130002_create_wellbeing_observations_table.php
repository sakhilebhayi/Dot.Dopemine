<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aggregate wellbeing guard (wiki.md §4 "Wellbeing observation",
 * natural key: mechanic + cohort + window). `cohort_size` is enforced
 * >= 50 at the model layer (App\Models\WellbeingObservation::booted()) —
 * wiki.md's "Aggregate only, n >= 50 — never individual-level" rule,
 * the fourth layer of this codebase's structural ethics gate alongside
 * the MechanicCategory enum, Mechanic::booted(), and
 * MechanicDeployment::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellbeing_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->string('cohort');
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedInteger('cohort_size');
            $table->decimal('wellbeing_movement', 8, 4);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['mechanic_id', 'cohort', 'window_start', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellbeing_observations');
    }
};
