<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per contributed object on a platform (an InfoDot question, answer, solution ...), with its lifecycle:
 * submitted -> validated -> published -> accepted | verified -> rewarded, or flagged / reversed. No content is
 * stored here, only the reference, who wrote it and where it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('platform', 32);
            $table->string('ref', 128);
            $table->uuid('user_uuid');
            $table->string('kind', 32);
            $table->string('status', 24)->default('submitted');
            $table->uuid('tenant_uuid')->nullable();
            $table->string('visibility', 16)->default('public');
            $table->string('parent_ref', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'ref']);
            $table->index('user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
