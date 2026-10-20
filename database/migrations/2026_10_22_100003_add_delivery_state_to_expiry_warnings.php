<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery bookkeeping for the 30-day heads-up. A row existing means "this lot is to be warned about"; these
 * columns say whether the warning has actually reached Dot.Notify. No address, name or message text is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expiry_warnings')) {
            return;
        }

        Schema::table('expiry_warnings', function (Blueprint $table) {
            if (! Schema::hasColumn('expiry_warnings', 'status')) {
                $table->string('status', 12)->default('pending'); // pending | delivered | moot | failed
            }
            if (! Schema::hasColumn('expiry_warnings', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0);
            }
            if (! Schema::hasColumn('expiry_warnings', 'next_attempt_at')) {
                $table->timestamp('next_attempt_at')->nullable();
            }
            if (! Schema::hasColumn('expiry_warnings', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
            if (! Schema::hasColumn('expiry_warnings', 'notify_status')) {
                $table->string('notify_status', 24)->nullable(); // what Dot.Notify said it did: routed, no_rule ...
            }
            if (! Schema::hasColumn('expiry_warnings', 'last_error')) {
                $table->string('last_error', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expiry_warnings')) {
            return;
        }

        foreach (['status', 'attempts', 'next_attempt_at', 'delivered_at', 'notify_status', 'last_error'] as $column) {
            if (Schema::hasColumn('expiry_warnings', $column)) {
                Schema::table('expiry_warnings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
