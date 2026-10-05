<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * The job already running for an asset (waiting for review or not yet done), if any — so a second
 * report of the same device does not open another ticket.
 */
class OpenTicketOfAsset
{
    public const RUNNING = [Ticket::STATUS_PENDING_REVIEW, ...Ticket::OPEN_STATUSES];

    public function handle(int $assetId): ?Ticket
    {
        return Ticket::query()->where('asset_id', $assetId)->whereIn('status', self::RUNNING)->latest('id')->first();
    }
}
