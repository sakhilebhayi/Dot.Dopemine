<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard `database` notification channel table (matches the
 * output of `php artisan notifications:table`), added for the in-app
 * notification bell so catalog events (mechanic certified/decertified,
 * deployment started/retired — wiki.md §5) have somewhere to land. No
 * event/listener wiring emits notifications automatically yet — see
 * wiki.md Roadmap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
