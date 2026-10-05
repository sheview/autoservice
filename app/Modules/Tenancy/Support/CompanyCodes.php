<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Company codes (001, 002, ...) both ways, kept for the request: the code of a company, and the
 * company of a code a customer typed ("001", "1", " 001 " all find company 001).
 */
class CompanyCodes
{
    /** @var array<int, string|null> */
    private static array $codes = [];

    public static function of(?int $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        return self::$codes[$tenantId] ??= Tenant::withTrashed()->whereKey($tenantId)->value('company_code');
    }

    /** The active company with this code, or null. */
    public static function tenant(string $typed): ?Tenant
    {
        $typed = trim($typed);
        if (! ctype_digit($typed) || (int) $typed === 0) {
            return null;
        }

        return Tenant::query()
            ->where('is_platform', false)
            ->whereRaw('company_code ~ \'^[0-9]+$\' and company_code::int = ?', [(int) $typed])
            ->first();
    }

    /** Forget what was kept (tests, long-running workers). */
    public static function forget(): void
    {
        self::$codes = [];
    }
}
