<?php

namespace App\Modules\Service\Actions;

use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSla;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The helpdesk's answer to a problem reported with a QR code (pending review):
 *   accept  it joins the queue as a new job, under the device's covering MA contract and its SLA
 *   ask     the customer is asked for more (the message shows on their tracking page); still pending
 *   reject  it is cancelled, with a reason the customer reads on their tracking page
 * The message is what the customer sees — never an internal note.
 */
class ReviewReportedTicket
{
    public const DECISIONS = ['accept', 'ask', 'reject'];

    public function __construct(
        private RecordTicketEvent $recordEvent,
        private CoveringContracts $coveringContracts,
        private TicketSla $sla,
    ) {}

    public function handle(Ticket $ticket, string $decision, ?string $message, User $actor): Ticket
    {
        if ($ticket->status !== Ticket::STATUS_PENDING_REVIEW) {
            throw ValidationException::withMessages(['decision' => __('service.reported.not_pending')]);
        }
        if (in_array($decision, ['ask', 'reject'], true) && blank($message)) {
            throw ValidationException::withMessages(['message' => __('service.reported.message_required')]);
        }

        return DB::transaction(function () use ($ticket, $decision, $message, $actor) {
            $message = filled($message) ? trim($message) : null;

            if ($decision === 'ask') {
                $ticket->forceFill(['customer_message' => $message])->save();
                $this->recordEvent->handle($ticket, TicketEvent::TYPE_COMMENT, $actor, ['body' => __('service.reported.asked', ['message' => $message])]);

                return $ticket;
            }

            $to = $decision === 'accept' ? Ticket::STATUS_NEW : Ticket::STATUS_CANCELLED;
            if ($decision === 'accept') {
                $contract = collect($this->coveringContracts->handle($ticket->customer_id, $ticket->asset_id))->first();
                $ticket->contract_id = $contract['id'] ?? null;
                $ticket->service_window = $contract['service_window'] ?? null;
                $ticket->response_minutes = $contract['slas'][$ticket->priority]['response_minutes'] ?? null;
                $ticket->resolve_minutes = $contract['slas'][$ticket->priority]['resolve_minutes'] ?? null;
                $this->sla->refreshDueDates($ticket);
            } else {
                $ticket->cancelled_at = now();
            }
            $ticket->status = $to;
            $ticket->customer_message = $message ?? $ticket->customer_message;
            $ticket->save();

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_STATUS, $actor, [
                'from_status' => Ticket::STATUS_PENDING_REVIEW,
                'to_status' => $to,
                'body' => $message,
            ]);

            return $ticket;
        });
    }
}
