<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Grants (or withdraws) access to the Dot Points back-office. The only way to set `users.is_admin`. */
class MakePointsAdmin extends Command
{
    protected $signature = 'points:make-admin {email : The email of an existing user} {--revoke : Withdraw administrator access instead}';

    protected $description = 'Make an existing user an administrator of the Dot Points back-office (or revoke it)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email. They must sign in once first.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin ? "{$user->name} is now a Dot Points administrator." : "{$user->name} is no longer a Dot Points administrator.");

        return self::SUCCESS;
    }
}
