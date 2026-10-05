<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetDevices;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Platform\Actions\SendAlert;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A problem reported by anyone with an asset's QR code, without signing in. It waits for the
 * helpdesk ("pending review": no SLA clock, not in the technicians' queue) and the company's alert
 * channels hear of it at once. A device that already has a running job gets no second ticket:
 * that one is returned instead (checked again here, under a lock, for two reports at once).
 */
class OpenReportedTicket
{
    public function __construct(
        private GenerateTicketNumber $generateNumber,
        private RecordTicketEvent $recordEvent,
        private AssetDetails $assetDetails,
        private AssetDevices $assetDevices,
        private OpenTicketOfAsset $openTicketOfAsset,
    ) {}

    /**
     * @param  array{symptoms: list<string>, note?: string|null, name: string, phone?: string|null, email?: string|null}  $data  validated
     * @param  list<UploadedFile>  $photos
     * @return array{ticket: Ticket, created: bool}
     */
    public function handle(int $assetId, array $data, array $photos = []): array
    {
        return DB::transaction(function () use ($assetId, $data, $photos) {
            // One report at a time per device.
            DB::table('assets')->where('id', $assetId)->lockForUpdate()->first();
            if ($running = $this->openTicketOfAsset->handle($assetId)) {
                return ['ticket' => $running, 'created' => false];
            }

            $asset = $this->assetDetails->handle([$assetId])[$assetId];
            $device = $this->assetDevices->handle([$assetId])[$assetId] ?? null;
            $symptoms = implode(', ', $data['symptoms']);

            $ticket = new Ticket([
                'title' => Str::limit(__('service.reported.title', ['symptoms' => $symptoms]), 250),
                'description' => trim($symptoms."\n\n".($data['note'] ?? '')),
                'priority' => 'medium',
                'source' => Ticket::SOURCE_QR,
                'contact_name' => $data['name'],
                'contact_phone' => $data['phone'] ?? null,
                'contact_email' => $data['email'] ?? null,
                'asset_id' => $assetId,
                'customer_id' => $asset['customer_id'],
                'device_name' => $device['name'] ?? $asset['name'],
                'device_brand' => $device['brand'] ?? null,
                'device_model' => $device['model'] ?? null,
                'device_serial' => $device['serial_number'] ?? null,
                'device_location' => $device['location'] ?? null,
                'device_ip' => $device['ip_address'] ?? null,
            ]);
            $ticket->status = Ticket::STATUS_PENDING_REVIEW;
            $ticket->ticket_no = $this->generateNumber->handle();
            $ticket->branch_id = $asset['branch_id'];
            $ticket->created_at = now();
            $ticket->save();

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_CREATED, null, [
                'to_status' => Ticket::STATUS_PENDING_REVIEW,
                // Not the name here: it lives only in contact_name, which is blanked out later.
                'body' => __('service.reported.by'),
            ]);

            foreach ($photos as $file) {
                $ticket->addMedia($file)->withCustomProperties(['stage' => 'reported'])->toMediaCollection(Ticket::PHOTOS);
            }

            $customer = $asset['customer_id']
                ? collect(app(ListCustomers::class)->handle(withTrashed: true))->firstWhere('id', $asset['customer_id'])['name'] ?? '-'
                : '-';
            app(SendAlert::class)->handle('ticket_reported', [
                'no' => $ticket->ticket_no,
                'device' => trim($asset['asset_code'].' '.$asset['name']),
                'customer' => $customer,
                'symptoms' => $symptoms,
                'reporter' => $data['name'],
                'contact' => collect([$data['phone'] ?? null, $data['email'] ?? null])->filter()->implode(' / '),
            ], route('service.tickets.show', $ticket));

            app(NotifyCustomer::class)->handle($ticket, 'received');

            return ['ticket' => $ticket, 'created' => true];
        });
    }
}
