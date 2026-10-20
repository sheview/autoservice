<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\CorrectAssetDetails;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Validation\ValidationException;

/**
 * The technician finds the register wrong on site (who uses the device, its IP, where it is, its property
 * number, its serial number) and fixes it from the work order: the asset itself is updated (Asset), so the sheet, which reads
 * the register until its report is approved, and every later round show the correction.
 */
class CorrectPmItemAsset
{
    public function __construct(private CorrectAssetDetails $correctAsset) {}

    /**
     * @param  array{used_by?: string|null, ip_address?: string|null, location?: string|null, property_no?: string|null, serial_number?: string|null}  $data  validated
     */
    public function handle(PmVisitItem $item, array $data): void
    {
        if (! $item->visit->isWorking()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        if ($this->correctAsset->handle($item->asset_id, $data) === null) {
            throw ValidationException::withMessages(['asset' => __('maintenance.visits.asset_not_found')]);
        }
    }
}
