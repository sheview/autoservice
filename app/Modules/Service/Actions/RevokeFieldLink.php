<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Models\TicketFieldLink;

/**
 * Stops a ticket's link at once (what was already sent back stays).
 */
class RevokeFieldLink
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    public function handle(TicketFieldLink $link, User $actor): TicketFieldLink
    {
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now(), 'revoked_by_name' => $actor->name]);
            $this->recordEvent->handle($link->ticket, TicketEvent::TYPE_UPDATED, $actor, [
                'body' => __('service.field_links.revoked_event', ['name' => $link->holder_name]),
                'is_internal' => true,
            ]);
        }

        return $link;
    }
}
