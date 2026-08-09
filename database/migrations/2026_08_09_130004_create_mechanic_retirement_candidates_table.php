<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Level 2 proposal: engagement-up/outcome-flat decoupling detected for a
 * certified mechanic, awaiting a canGovern() admin's decision. Never
 * auto-executes — "confirmed" still requires an explicit admin action that
 * calls the existing, unchanged App\Actions\Dopemine\DecertifyMechanic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_retirement_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->decimal('coupling_rate', 5, 4);
            $table->unsignedInteger('sample_size');
            $table->string('status')->default('open');
            $table->timestamp('detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['mechanic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_retirement_candidates');
    }
};
