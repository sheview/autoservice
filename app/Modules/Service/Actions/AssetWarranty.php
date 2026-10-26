<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\AssetDevices;
use App\Modules\Service\Models\Ticket;

/**
 * What the asset register says about the warranty of a ticket's device today: in warranty up to
 * and including its end date. Null when the ticket has no registered device or the device has no
 * warranty date, so someone has to check it (CheckTicketWarranty).
 */
class AssetWarranty
{
    public function __construct(private AssetDevices $assetDevices) {}

    /**
     * @return array{status: string, expires_on: string}|null
     */
    public function handle(Ticket $ticket): ?array
    {
        if ($ticket->asset_id === null) {
            return null;
        }

        $expiresOn = $this->assetDevices->handle([$ticket->asset_id])[$ticket->asset_id]['warranty_expires_at'] ?? null;

        return $expiresOn === null ? null : [
            'status' => $expiresOn >= today()->toDateString() ? 'in_warranty' : 'out_of_warranty',
            'expires_on' => $expiresOn,
        ];
    }
}
