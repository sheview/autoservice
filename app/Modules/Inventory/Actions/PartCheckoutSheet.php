<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the part issue/loan form prints. The same page as an asset's (documents.asset-checkout,
 * with kind "part" for its titles): the part takes the asset's place.
 */
class PartCheckoutSheet
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PartCheckout $checkout): array
    {
        $part = $checkout->part;
        $tenant = $this->context->tenant();

        // The PDF service cannot sign in to fetch the logo, so it travels inside the page.
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        return [
            'kind' => 'part',
            'company' => $tenant ? CompanyProfile::of($tenant) : null,
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'checkout' => $checkout,
            'asset' => [
                'asset_code' => $part->code,
                'name' => $part->name,
                'brand' => $part->brand,
                'model' => null,
                'serial_number' => $part->part_number,
                'property_no' => null,
                'location' => null,
                'unit' => $part->unit,
                'category' => null,
            ],
        ];
    }
}
