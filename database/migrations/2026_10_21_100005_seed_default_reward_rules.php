<?php

use Database\Seeders\RewardRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The reward rules are configuration the ledger cannot work without. Seeding them from a migration means a
 * deploy always leaves the owner's starting schedule in place (an existing rule is never overwritten; later
 * changes are new versions written by an administrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RewardRuleSeeder)->run();
    }

    public function down(): void {}
};
