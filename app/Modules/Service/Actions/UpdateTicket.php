<?php

namespace App\Modules\Service\Actions;

use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSla;
use Illuminate\Support\Facades\DB;

/**
 * Edits the details of an open ticket. A new priority takes that priority's SLA from the
 * ticket's contract (as it covered the ticket when opened) and moves the due times.
 */
class UpdateTicket
{
    public const FIELDS = ['title', 'description', 'priority', 'source', 'contact_name', 'contact_phone'];

    public function __construct(
        private CoveringContracts $coveringContracts,
        private TicketSla $sla,
        private RecordTicketEvent $recordEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated, keys of FIELDS
     */
    public function handle(Ticket $ticket, array $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket->fill(collect($data)->only(self::FIELDS)->all());
            $changed = array_keys($ticket->getDirty());

            if ($ticket->isDirty('priority') && $ticket->contract_id !== null) {
                $contract = collect($this->coveringContracts->handle($ticket->customer_id, $ticket->asset_id, $ticket->created_at))
                    ->firstWhere('id', $ticket->contract_id);
                $ticket->response_minutes = $contract['slas'][$ticket->priority]['response_minutes'] ?? null;
                $ticket->resolve_minutes = $contract['slas'][$ticket->priority]['resolve_minutes'] ?? null;
                $this->sla->refreshDueDates($ticket);
            }

            $ticket->save();

            if ($changed !== []) {
                $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, $actor, [
                    'body' => collect($changed)->map(fn ($field) => __("service.fields.{$field}"))->join(', '),
                ]);
            }

            return $ticket;
        });
    }
}
