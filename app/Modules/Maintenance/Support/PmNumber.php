<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Tenancy\Support\CompanyCodes;

/**
 * PM work order numbers as customers see them: inside PM-2569-00012 (per company), shown on the
 * sheet, the tracking page and links as PM002-2569-00012 with the company code; a revision keeps
 * its -R1. Unchanged when the code is unknown.
 */
final class PmNumber
{
    public static function shown(string $visitNo, ?int $tenantId): string
    {
        $code = CompanyCodes::of($tenantId);

        return $code === null ? $visitNo : (preg_replace('/^PM-(\d{4})-/', "PM{$code}-$1-", $visitNo) ?? $visitNo);
    }
}
