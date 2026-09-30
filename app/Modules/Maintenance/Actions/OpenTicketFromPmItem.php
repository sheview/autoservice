<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Service\Actions\OpenTicket;
use App\Modules\Service\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a repair ticket (Service module) for an asset found with an issue during PM.
 * The ticket gets the round's contract when that contract still covers the asset today
 * (OpenTicket drops it otherwise, and the ticket has no SLA).
 */
class OpenTicketFromPmItem
{
    public function __construct(
        private OpenTicket $openTicket,
        private AssetDetails $assetDetails,
    ) {}

    public function handle(PmVisitItem $item, User $actor, string $priority): Ticket
    {
        if ($item->result !== PmVisitItem::RESULT_ISSUE || $item->ticket_id !== null) {
            throw ValidationException::withMessages(['item' => __('maintenance.visits.cannot_open_ticket')]);
        }

        return DB::transaction(function () use ($item, $actor, $priority) {
            $visit = $item->visit;
            $asset = $this->assetDetails->handle([$item->asset_id])[$item->asset_id] ?? null;

            $ticket = $this->openTicket->handle($actor, [
                'customer_id' => $visit->customer_id,
                'asset_id' => $item->asset_id,
                'contract_id' => $visit->contract_id,
                'title' => __('maintenance.visits.ticket_title', ['no' => $visit->visit_no, 'asset' => $asset['asset_code'] ?? '']),
                'description' => $item->note,
                'priority' => $priority,
                'source' => 'pm',
            ]);

            $item->update(['ticket_id' => $ticket->id]);

            return $ticket;
        });
    }
}
