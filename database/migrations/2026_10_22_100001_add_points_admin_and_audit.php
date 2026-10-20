<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may run the Dot Points back-office, and the record of what they did.
 *
 * `users.is_admin` is the smallest administrator concept this app can have: Dopemine has team roles, but anyone can
 * create a team and own it, so a team role cannot say "may change what points are worth". The flag is never mass
 * assignable and is set only from the command line (`php artisan points:make-admin <email>`).
 *
 * `admin_audits` is append-only (the model refuses edits and deletes). It keeps who, when and why for every
 * administrative change that does not already carry that in its own row. `actor_id` is deliberately not a foreign
 * key, so the record outlives the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false);
            });
        }

        if (! Schema::hasTable('admin_audits')) {
            Schema::create('admin_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('actor_label', 191);
                $table->string('action', 64);
                $table->string('subject_type', 32);
                $table->string('subject_key', 191);
                $table->text('reason');
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['subject_type', 'subject_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audits');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
    }
};
