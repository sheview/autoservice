<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * The device (asset) a ticket is about, deleted tickets too, for other modules: parts handed out
 * for the job go into it.
 */
class TicketAssetId
{
    public function handle(?int $ticketId): ?int
    {
        $id = $ticketId === null ? null : Ticket::withTrashed()->whereKey($ticketId)->value('asset_id');

        return $id === null ? null : (int) $id;
    }
}
