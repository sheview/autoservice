<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;

/**
 * Adds an entry to a ticket's timeline, with the actor's name as it is now.
 */
class RecordTicketEvent
{
    /**
     * @param  array{from_status?: string|null, to_status?: string|null, body?: string|null, is_internal?: bool}  $details
     */
    public function handle(Ticket $ticket, string $type, ?User $actor, array $details = []): TicketEvent
    {
        return $ticket->events()->create($details + [
            'type' => $type,
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
        ]);
    }
}
