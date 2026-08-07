<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The negative catalog (wiki.md §4 "Prohibited-metric entry", §6 "What We
 * Will Not Build"). Global reference table — not team-scoped, not editable
 * from any consuming platform. It exists so the ethics constraint is
 * queryable and visible in the product, not just documented in wiki.md.
 *
 * This table is a *documentation and audit* layer, not the enforcement
 * mechanism itself: the enforcement is the fixed MechanicCategory enum (see
 * migration 2026_08_01_120001) which makes it structurally impossible to
 * create a mechanic whose category matches one of these patterns. This
 * table records, by name, which patterns were considered and rejected, and
 * why — visible on the catalog dashboard so the restraint is legible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prohibited_metric_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern')->unique();
            $table->text('reason');
            $table->text('example')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prohibited_metric_patterns');
    }
};
