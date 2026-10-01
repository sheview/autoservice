<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use Illuminate\Support\Facades\DB;

/**
 * The repair report of the job sheet, kept by the technician: what caused the problem, any extra
 * cost (satang) and who on the customer's side approved the repair. Printed on the job sheet.
 */
class SaveRepairReport
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    /**
     * @param  array{cause: string|null, extra_cost: int|null, approver_name: string|null}  $report  extra_cost in satang
     */
    public function handle(Ticket $ticket, User $actor, array $report): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor, $report) {
            $ticket->fill($report);
            if (! $ticket->isDirty()) {
                return $ticket;
            }

            $ticket->save();
            $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, $actor, [
                'body' => __('service.fields.repair_report'),
                // The cost is the company's business, not the customer portal's.
                'is_internal' => true,
            ]);

            return $ticket;
        });
    }
}
