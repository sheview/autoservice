<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetDevices;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Support\TicketSla;
use Illuminate\Support\Facades\DB;

/**
 * Opens a ticket: number, branch (from the asset), SLA copied from the covering contract,
 * due times, and the first timeline entries. With an assignee it starts as "assigned".
 */
class OpenTicket
{
    public function __construct(
        private GenerateTicketNumber $generateNumber,
        private AssetDetails $assetDetails,
        private AssetDevices $assetDevices,
        private CoveringContracts $coveringContracts,
        private TicketSla $sla,
        private RecordTicketEvent $recordEvent,
        private UserNames $userNames,
        private NotifyTicketEvent $notify,
    ) {}

    /**
     * @param  array{customer_id?: int|null, asset_id?: int|null, contract_id?: int|null, title: string,
     *     description?: string|null, priority: string, source: string, contact_name?: string|null,
     *     contact_phone?: string|null, assignee_id?: int|null, device_name?: string|null, device_brand?: string|null,
     *     device_model?: string|null, device_serial?: string|null, device_serial_unknown?: bool, device_location?: string|null,
     *     device_ip?: string|null}  $data
     *     validated (asset, contract and customer agree); the device fields describe a device that is not in the system
     */
    public function handle(User $actor, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $data) {
            $asset = isset($data['asset_id']) ? ($this->assetDetails->handle([$data['asset_id']])[$data['asset_id']] ?? null) : null;

            $ticket = new Ticket(collect($data)->except('assignee_id')->all());
            if ($asset !== null) {
                $this->copyDevice($ticket, $asset['id']);
            }
            $ticket->ticket_no = $this->generateNumber->handle();
            $ticket->customer_id = $asset['customer_id'] ?? $data['customer_id'] ?? null;
            $ticket->branch_id = $asset['branch_id'] ?? $actor->branch_id;
            $ticket->reported_by = $actor->id;
            $ticket->created_at = now();
            $this->copySla($ticket, $actor);
            $this->sla->refreshDueDates($ticket);
            $ticket->save();

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_CREATED, $actor, ['to_status' => Ticket::STATUS_NEW]);

            if (! empty($data['assignee_id'])) {
                $ticket->update(['assignee_id' => $data['assignee_id'], 'status' => Ticket::STATUS_ASSIGNED]);
                $this->recordEvent->handle($ticket, TicketEvent::TYPE_ASSIGNED, $actor, [
                    'from_status' => Ticket::STATUS_NEW,
                    'to_status' => Ticket::STATUS_ASSIGNED,
                    'body' => $this->userNames->handle([$data['assignee_id']])[$data['assignee_id']] ?? null,
                ]);
            }

            // A ticket from the portal needs someone to pick it up.
            if ($actor->customer_id !== null) {
                $this->notify->handle($ticket, 'opened', $actor);
            }
            if ($ticket->assignee_id !== null) {
                $this->notify->handle($ticket, 'assigned', $actor);
            }

            return $ticket;
        });
    }

    /**
     * A registered asset: the job sheet keeps the device as it was when the job was opened, so nobody
     * types it again.
     */
    private function copyDevice(Ticket $ticket, int $assetId): void
    {
        $device = $this->assetDevices->handle([$assetId])[$assetId] ?? null;
        if ($device === null) {
            return;
        }

        $ticket->fill([
            'device_name' => $device['name'],
            'device_brand' => $device['brand'],
            'device_model' => $device['model'],
            'device_serial' => $device['serial_number'],
            'device_serial_unknown' => false,
            'device_location' => $device['location'],
            'device_ip' => $device['ip_address'],
        ]);
    }

    /**
     * Copy the service window and the SLA of the ticket's priority from its contract.
     * A customer account does not choose the contract: the first covering one is used.
     */
    private function copySla(Ticket $ticket, User $actor): void
    {
        $covering = collect($this->coveringContracts->handle($ticket->customer_id, $ticket->asset_id));
        $contract = $actor->customer_id !== null && $ticket->contract_id === null
            ? $covering->first()
            : $covering->firstWhere('id', $ticket->contract_id);

        $ticket->contract_id = $contract['id'] ?? null;
        if ($contract === null) {
            return;
        }

        $ticket->service_window = $contract['service_window'];
        $ticket->response_minutes = $contract['slas'][$ticket->priority]['response_minutes'] ?? null;
        $ticket->resolve_minutes = $contract['slas'][$ticket->priority]['resolve_minutes'] ?? null;
    }
}
