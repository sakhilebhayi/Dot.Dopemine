<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Points\ContributionTenantRecipients;
use App\Services\Points\ExpiryWarningRecipients;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ExpiryWarningRecipients::class, ContributionTenantRecipients::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // The Dot Points back-office: reward rules, price list, redemption queue, ledger explorer. Only people
        // flagged by `php artisan points:make-admin`; a team role is not enough, since anyone can own a team.
        Gate::define('manage-points', fn (User $user): bool => (bool) $user->is_admin);
    }
}
