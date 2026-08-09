<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * engagement.outcome_coupling_rate (Dot.Brain audit §11), written by
 * App\Console\Commands\ScanMechanicDecoupling. Pure computed reporting
 * output — this migration performs no mutation to certification status.
 * Both columns stay null for a mechanic with no MechanicOutcome records in
 * the scan window (no data, not a false "fully decoupled" 0% reading).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->decimal('coupling_rate', 5, 4)->nullable()->after('decertified_at');
            $table->timestamp('coupling_rate_computed_at')->nullable()->after('coupling_rate');
        });
    }

    public function down(): void
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->dropColumn(['coupling_rate', 'coupling_rate_computed_at']);
        });
    }
};
