<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Points\ContributionTenantRecipients;
use App\Services\Points\ExpiryWarningRecipients;
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
        // The Dot Points back-office: reward rules, price list, redemption queue, ledger explorer. Only people
        // flagged by `php artisan points:make-admin`; a team role is not enough, since anyone can own a team.
        Gate::define('manage-points', fn (User $user): bool => (bool) $user->is_admin);
    }
}
