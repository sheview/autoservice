<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * The address in a work order's tracking QR (pm-track/{token}): its token is made the first time and
 * kept, so every print shows the same QR. The company's public address (APP_URL / its subdomain).
 */
class PmTrackingUrl
{
    public function __construct(private TenantContext $context) {}

    public function handle(PmVisit $visit): string
    {
        if ($visit->tracking_token === null) {
            $visit->forceFill(['tracking_token' => ShareToken::make()])->saveQuietly();
        }

        return PublicUrl::forTenant($this->context->tenant(), '/pm-track/'.$visit->tracking_token);
    }
}
