<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Models\TicketFieldLink;
use Illuminate\Validation\ValidationException;

/**
 * Makes a link to work on an open ticket without an account: for an outside technician to fill
 * in the job ("work"), or for the customer only to sign it off ("sign"). Kept in the ticket's
 * history (internal) with who made it, for whom and until when.
 */
class CreateFieldLink
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    /**
     * @param  array{mode: string, holder_name: string, holder_company?: string|null, holder_phone?: string|null, days?: int|null}  $data
     */
    public function handle(Ticket $ticket, array $data, User $actor): TicketFieldLink
    {
        if (in_array($ticket->status, [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['holder_name' => __('service.field_links.ticket_closed')]);
        }
        $days = min(TicketFieldLink::MAX_DAYS, max(1, (int) ($data['days'] ?? TicketFieldLink::DEFAULT_DAYS)));

        $link = TicketFieldLink::create([
            'ticket_id' => $ticket->id,
            'token' => TicketFieldLink::newToken(),
            'mode' => $data['mode'],
            'holder_name' => trim($data['holder_name']),
            'holder_company' => $data['holder_company'] ?? null,
            'holder_phone' => $data['holder_phone'] ?? null,
            'expires_at' => now()->addDays($days)->endOfDay(),
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ]);

        $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, $actor, [
            'body' => __("service.field_links.created_{$link->mode}", ['name' => $link->holder_name, 'until' => $link->expires_at->format('d/m/Y')]),
            'is_internal' => true,
        ]);

        return $link;
    }
}
