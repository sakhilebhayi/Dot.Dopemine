<?php

use Database\Seeders\RewardRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the `seminar_hosted` rule (version 1) to a database whose starting schedule was seeded earlier. The seeder only
 * ever creates a missing (key, version) row, so every rule already there, including any later version an
 * administrator published, is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RewardRuleSeeder)->run();
    }

    public function down(): void {}
};
