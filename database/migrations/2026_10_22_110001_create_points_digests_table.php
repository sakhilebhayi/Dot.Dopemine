<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly / monthly summary InfoDot pulls and delivers itself (docs/points-digests-api.md). One row per person
 * per closed period. It holds the figures only: no address, no name, no contribution title or content. Additive and
 * guarded, so running it twice or on a database that already has the table changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('points_digests')) {
            return;
        }

        Schema::create('points_digests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->string('period', 8); // week | month
            $table->date('period_start');
            $table->date('period_end');
            $table->json('payload');
            $table->string('state', 12)->default('pending'); // pending | delivered | skipped | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestamps();

            $table->unique(['user_uuid', 'period', 'period_start']);
            $table->index(['period', 'period_start', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_digests');
    }
};
