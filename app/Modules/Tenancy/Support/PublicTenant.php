<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Which company a public page (no sign-in needed: ticket tracking, an asset's QR page, the report
 * form) is about. The one place that decides it, in this order:
 *   1. the company of the request — its subdomain, or the signed-in user's own (ResolveTenant);
 *      a code typed in then never takes over
 *   2. the company code inside a ticket number typed in (TK001-2569-00001)
 *   3. a company code typed in: the subdomain name (netpro) or the number (001, 1)
 * Null when none of them names an active company; callers then answer with the same "not found"
 * as for anything else, so nobody learns which companies exist.
 */
class PublicTenant
{
    public function __construct(private TenantContext $context) {}

    /** Whether the request already names its company (no company code to ask for). */
    public function known(): bool
    {
        $tenant = $this->context->tenant();

        return $tenant !== null && ! $tenant->is_platform;
    }

    public function resolve(?string $typedCompany = null, ?string $typedCodeInNumber = null): ?Tenant
    {
        if ($this->known()) {
            return $this->usable($this->context->tenant());
        }

        if (filled($typedCodeInNumber)) {
            return $this->usable(CompanyCodes::tenant($typedCodeInNumber));
        }

        $typed = mb_strtolower(trim((string) $typedCompany));
        if ($typed === '') {
            return null;
        }

        return $this->usable(ctype_digit($typed)
            ? CompanyCodes::tenant($typed)
            : Tenant::query()->where('subdomain', $typed)->where('is_platform', false)->first());
    }

    private function usable(?Tenant $tenant): ?Tenant
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() ? $tenant : null;
    }
}
