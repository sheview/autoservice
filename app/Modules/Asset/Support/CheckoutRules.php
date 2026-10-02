<?php

namespace App\Modules\Asset\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * The company's rules for issue/loan requests, kept in tenants.settings "checkout":
 * auto_approve_limit = a request of parts only, each line worth at most this (satang), skips
 * approval (null = always approved by someone). Set on the company page (company.manage).
 */
class CheckoutRules
{
    public static function autoApproveLimit(?Tenant $tenant): ?int
    {
        $limit = $tenant?->settings['checkout']['auto_approve_limit'] ?? null;

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }
}
