<?php

namespace App\Modules\Service\Actions;

use App\Modules\Platform\Actions\SendAlert;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSla;
use Illuminate\Support\Facades\DB;

/**
 * A ticket of the current company sent on by another company through a share (Platform\CrossTenant):
 * the job as they described it, with no customer, asset or contract of ours (the dispatcher here
 * fills those in if they apply). Nobody here opened it, so no person is linked.
 */
class OpenForwardedTicket
{
    public function __construct(
        private GenerateTicketNumber $generateNumber,
        private TicketSla $sla,
        private RecordTicketEvent $recordEvent,
    ) {}

    /**
     * @param  string  $from  who sent it, e.g. "TK-2569-00012 · Company A · Somchai"
     * @param  array{title: string, description?: string|null, priority: string, contact_name?: string|null,
     *     contact_phone?: string|null, device_name?: string|null, device_brand?: string|null, device_model?: string|null,
     *     device_serial?: string|null, device_serial_unknown?: bool, device_location?: string|null, device_ip?: string|null}  $data
     */
    public function handle(string $from, array $data): Ticket
    {
        return DB::transaction(function () use ($from, $data) {
            $ticket = new Ticket([...$data, 'source' => Ticket::SOURCE_PARTNER]);
            $ticket->ticket_no = $this->generateNumber->handle();
            $ticket->created_at = now();
            $this->sla->refreshDueDates($ticket);
            $ticket->save();

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_CREATED, null, [
                'to_status' => Ticket::STATUS_NEW,
                'body' => __('service.tickets.forwarded_from', ['from' => $from]),
            ]);

            app(SendAlert::class)->handle('ticket_opened', [
                'no' => $ticket->ticket_no,
                'title' => $ticket->title,
                'priority' => __("ui.tickets.priorities.{$ticket->priority}"),
                'contact' => $ticket->contact_name,
                'actor' => $from,
            ], route('service.tickets.show', $ticket));

            return $ticket;
        });
    }
}
