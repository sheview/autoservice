<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Platform\Support\Money;
use App\Modules\Tenancy\Models\Tenant;

/**
 * How a company presents itself on what it prints (QR labels, documents): its name, logo and
 * where to call for service. The logo is the tenant's "logo" media; phone and e-mail are in the
 * tenant's settings. Seen with company.view, changed with company.manage.
 */
class CompanyProfile
{
    /** Who may change the profile. */
    public const PERMISSION = 'company.manage';

    /** Who may open the profile page. */
    public const VIEW_PERMISSION = 'company.view';

    public const LOGO = 'logo';

    public const LOGO_MAX_KB = 2048;

    public const LOGO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Keys in tenants.settings. */
    public const FIELDS = ['service_phone', 'service_email'];

    /**
     * @return array{name: string, service_phone: string|null, service_email: string|null, logo_url: string|null, auto_approve_limit: string|null}
     */
    public static function of(Tenant $tenant): array
    {
        $logo = $tenant->getFirstMedia(self::LOGO);

        return [
            'name' => $tenant->name,
            'service_phone' => $tenant->settings['service_phone'] ?? null,
            'service_email' => $tenant->settings['service_email'] ?? null,
            // The media id changes with a new logo, so the browser does not show the old one.
            'logo_url' => $logo ? route('tenancy.company.logo', ['v' => $logo->id]) : null,
            // Issue/loan requests of cheap parts skip approval (baht; null = off).
            'auto_approve_limit' => Money::toBaht($tenant->settings['checkout']['auto_approve_limit'] ?? null),
        ];
    }
}
