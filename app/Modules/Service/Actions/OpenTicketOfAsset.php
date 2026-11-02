<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * The job already running for an asset (waiting for review or not yet done), if any — so a second
 * report of the same device does not open another ticket. With $untilClosed a job that is resolved
 * but not yet closed counts too: a new ticket for the device waits until the old one is closed.
 */
class OpenTicketOfAsset
{
    public const RUNNING = [Ticket::STATUS_PENDING_REVIEW, ...Ticket::OPEN_STATUSES];

    public const NOT_CLOSED = [...self::RUNNING, Ticket::STATUS_RESOLVED];

    public function handle(int $assetId, bool $untilClosed = false): ?Ticket
    {
        return Ticket::query()->where('asset_id', $assetId)
            ->whereIn('status', $untilClosed ? self::NOT_CLOSED : self::RUNNING)
            ->latest('id')->first();
    }
}
