<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The price list. Points have no cash value and cannot be transferred; a reward costs a published number of points.
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('kind', 24); // billing_credit | capacity | marketplace
            $table->unsignedInteger('price_points');
            $table->unsignedInteger('monthly_cap')->nullable(); // redemptions per calendar month across all people
            $table->boolean('active')->default(true);
            $table->json('params')->nullable();
            $table->timestamps();
        });

        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key', 120)->unique();
            $table->uuid('user_uuid');
            $table->foreignId('reward_id')->constrained('rewards');
            $table->unsignedInteger('points');
            $table->string('status', 16)->default('pending'); // pending | fulfilled | failed | cancelled
            $table->string('fulfilment_ref')->nullable();
            $table->string('fulfilled_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_uuid', 'status']);
        });

        // Bookkeeping for the single 30-day heads-up before a lot expires (the ledger itself is append-only).
        Schema::create('expiry_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->unique()->constrained('ledger_entries');
            $table->uuid('user_uuid');
            $table->unsignedInteger('points');
            $table->timestamp('warned_at');
        });

        // Every event received from a platform, for audit and replay protection by event id.
        Schema::create('reward_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->string('platform', 32);
            $table->string('type', 80);
            $table->uuid('user_uuid')->nullable();
            $table->string('outcome', 24);
            $table->text('detail')->nullable();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_events');
        Schema::dropIfExists('expiry_warnings');
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('rewards');
    }
};
