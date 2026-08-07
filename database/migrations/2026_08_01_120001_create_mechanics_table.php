<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Mechanic Catalog (wiki.md §3, §4). Deliberately GLOBAL, not team-scoped:
 * a mechanic is a shared definition ("milestone celebration", "mastery
 * progress bar") that any team on any consuming platform can deploy, in the
 * same way Dot.Design's token/component library is a shared catalog rather
 * than a per-team asset. Team-specific usage lives in `mechanic_deployments`.
 *
 * `category` is a fixed-vocabulary enum column, not free text — see
 * App\Enums\MechanicCategory. This is the structural half of the ethics
 * gate: the database itself cannot hold a loss-framed or FOMO category
 * because no such value exists in the enum.
 *
 * `acid_test_passed` + `acid_test_notes` record the wiki.md §2 verdict this
 * platform is required to log for every mechanic it offers, before it may be
 * certified. `status` starts at `proposed` and can only reach `certified`
 * through App\Actions\Dopemine\CertifyMechanic, which refuses to certify a
 * mechanic whose acid-test verdict has not passed (also enforced again at
 * the model layer in App\Models\Mechanic — see its `saving` listener).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description');
            $table->enum('category', [
                'progress',
                'achievement',
                'mastery',
                'community',
                'momentum',
                'purpose',
                'learning',
                'confidence',
            ]);
            $table->enum('status', ['proposed', 'certified', 'decertified'])->default('proposed');
            $table->boolean('acid_test_passed')->default(false);
            $table->text('acid_test_notes')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->text('decertification_reason')->nullable();
            $table->timestamp('decertified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanics');
    }
};
