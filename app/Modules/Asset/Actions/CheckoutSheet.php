<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the issue/loan form (documents.asset-checkout) prints: the company, the form, the asset,
 * and the people who sign it.
 */
class CheckoutSheet
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(AssetCheckout $checkout): array
    {
        $asset = $checkout->asset()->with('category:id,name')->first();
        $tenant = $this->context->tenant();

        // The PDF service cannot sign in to fetch the logo, so it travels inside the page.
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        return [
            'company' => $tenant ? CompanyProfile::of($tenant) : null,
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'checkout' => $checkout,
            'asset' => [
                ...$asset->only(['asset_code', 'name', 'brand', 'model', 'serial_number', 'property_no', 'location']),
                'category' => $asset->category?->name,
            ],
        ];
    }
}
