<?php

namespace App\Services\Points;

use App\Models\Contribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The default way to address a warning, using only what Dopemine already holds and nothing it would have to ask
 * InfoDot for:
 *  - company: the tenant of the person's most recent contribution (contributions.tenant_uuid, which the signed
 *    intake already records);
 *  - email: a local `users` row carrying the same `uuid`, when this database has such a column (it does where
 *    Dopemine shares InfoDot's users table; it does not on the stand-alone SQLite install).
 * With either missing it returns null, and the warning waits instead of being sent to a guess.
 */
class ContributionTenantRecipients implements ExpiryWarningRecipients
{
    private ?bool $canFindEmails = null;

    public function for(string $userUuid): ?array
    {
        $company = Contribution::where('user_uuid', $userUuid)->whereNotNull('tenant_uuid')->orderByDesc('id')->value('tenant_uuid');

        if (! filled($company) || ! $this->canFindEmails()) {
            return null;
        }

        $email = DB::table('users')->where('uuid', $userUuid)->value('email');

        return filled($email) ? ['company_uuid' => (string) $company, 'email' => (string) $email] : null;
    }

    private function canFindEmails(): bool
    {
        return $this->canFindEmails ??= Schema::hasTable('users') && Schema::hasColumn('users', 'uuid') && Schema::hasColumn('users', 'email');
    }
}
