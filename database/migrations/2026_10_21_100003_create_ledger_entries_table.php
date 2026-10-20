<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Dot Points ledger. Append-only: rows are never updated or deleted (the model refuses). Points are
 * signed. A balance is always derived by summing, so nothing stored here can drift.
 *
 *   award    +  a lot of points that expires 12 months later
 *   refund   +  a failed redemption handed back (a new lot)
 *   redeem   -  spent from one lot (lot_id), oldest expiry first
 *   expire   -  the unspent remainder of a lot that passed its expiry
 *   reverse  -  the unspent part of a lot that was fraudulent, duplicated or invalidated
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->string('type', 16);
            $table->integer('points');
            $table->string('rule_key', 80)->nullable();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('contribution_ref', 160)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('lot_id')->nullable()->constrained('ledger_entries');
            $table->timestamp('expires_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_uuid', 'type']);
            $table->index('lot_id');
            $table->index('contribution_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
