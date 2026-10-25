<?php

namespace App\Services\Points;

/**
 * Who a warning is addressed to. Dot.Notify's contract needs a company uuid (which of its companies sends it) and
 * a recipient email; the ledger knows neither, only a user uuid. Whatever answers this must say so honestly: null
 * means "cannot be addressed", and the warning is not sent.
 */
interface ExpiryWarningRecipients
{
    /** @return array{company_uuid: string, email: string}|null */
    public function for(string $userUuid): ?array;
}
