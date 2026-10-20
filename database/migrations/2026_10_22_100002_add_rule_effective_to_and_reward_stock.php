<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A rule version is immutable except for one thing: when the next version replaces it, it is stamped with
        // the moment it stopped applying. Set once, never changed (the model refuses anything else).
        if (Schema::hasTable('reward_rules') && ! Schema::hasColumn('reward_rules', 'effective_to')) {
            Schema::table('reward_rules', function (Blueprint $table) {
                $table->timestamp('effective_to')->nullable();
            });
        }

        // Existing versions that were already replaced get the moment their successor took over.
        if (Schema::hasTable('reward_rules') && Schema::hasColumn('reward_rules', 'effective_to')) {
            $rows = DB::table('reward_rules')->orderBy('key')->orderBy('version')->get(['id', 'key', 'version', 'effective_from']);
            foreach ($rows->groupBy('key') as $versions) {
                $versions = $versions->values();
                foreach ($versions as $i => $row) {
                    if (isset($versions[$i + 1])) {
                        DB::table('reward_rules')->where('id', $row->id)->whereNull('effective_to')->update(['effective_to' => $versions[$i + 1]->effective_from]);
                    }
                }
            }
        }

        // Optional stock of a reward (a number of units left); null means unlimited.
        if (Schema::hasTable('rewards') && ! Schema::hasColumn('rewards', 'stock')) {
            Schema::table('rewards', function (Blueprint $table) {
                $table->unsignedInteger('stock')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reward_rules') && Schema::hasColumn('reward_rules', 'effective_to')) {
            Schema::table('reward_rules', function (Blueprint $table) {
                $table->dropColumn('effective_to');
            });
        }

        if (Schema::hasTable('rewards') && Schema::hasColumn('rewards', 'stock')) {
            Schema::table('rewards', function (Blueprint $table) {
                $table->dropColumn('stock');
            });
        }
    }
};
