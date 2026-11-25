<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\RequestPartsForTicket;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketRemovedPart;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finishing a job on a phone in one go: what was wrong and what fixed it, photos before and
 * after, the parts used (asked for on an issue request for the ticket, sent for approval: stock
 * moves when it is handed out, like any request), the pieces taken out of the device (a note),
 * where the technician was, and always the sign-off — as through a ticket's link (SubmitFieldReport):
 *   - on paper: the printed job sheet the customer filled in and signed, as a photo;
 *   - on the screen: a solution at least, the technician's signature, then the customer's.
 * Then the job is done (resolved), which tells whoever confirms it (and the company's alert
 * channels) as usual. All or nothing.
 */
class QuickCloseTicket
{
    public const CLOSABLE = [Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD];

    public function __construct(
        private MoveTicket $moveTicket,
        private CheckTicketWarranty $checkWarranty,
        private SaveRepairReport $saveReport,
        private RequestPartsForTicket $requestParts,
        private ListCustomers $customers,
        private AssetWarranty $assetWarranty,
    ) {}

    /**
     * @param  array{symptoms: list<string>, solutions: list<string>, note?: string|null, warranty_status?: string|null,
     *     parts?: list<array{part_id: int, qty: int}>, signer_name?: string|null, signature?: string|null,
     *     tech_signature?: string|null, lat?: float|null, lng?: float|null,
     *     removed?: list<array{item_name: string, serial_number?: string|null, problem?: string|null, disposition: string}>}  $data  validated
     * @param  array{before?: list<UploadedFile>, after?: list<UploadedFile>, signed_sheet?: UploadedFile|null}  $photos
     */
    public function handle(Ticket $ticket, User $actor, array $data, array $photos = []): Ticket
    {
        if (! in_array($ticket->status, self::CLOSABLE, true)) {
            throw ValidationException::withMessages(['ticket' => __('service.quick_close.not_closable')]);
        }

        // The sign-off: the signed paper sheet, or on the screen what was done and both signatures.
        $sheet = $photos['signed_sheet'] ?? null;
        $missing = array_filter([
            'signer_name' => blank($data['signer_name'] ?? null) ? __('service.field_links.signer_required') : null,
            ...($sheet !== null ? [] : [
                'solutions' => ($data['solutions'] ?? []) === [] ? __('service.field_links.solution_required') : null,
                'tech_signature' => blank($data['tech_signature'] ?? null) ? __('service.field_links.tech_signature_required') : null,
                'signature' => blank($data['signature'] ?? null) ? __('service.field_links.customer_signature_required') : null,
            ]),
        ]);
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        // The customer who signed off is who approved the repair.
        $approver = trim($data['signer_name']);
        // Not checked yet: what the technician picked, else the asset register's warranty date.
        $fromAsset = $ticket->warranty_checked_at === null && blank($data['warranty_status'] ?? null)
            ? $this->assetWarranty->handle($ticket)
            : null;
        if ($ticket->warranty_checked_at === null && blank($data['warranty_status'] ?? null) && $fromAsset === null) {
            throw ValidationException::withMessages(['warranty_status' => __('service.tickets.warranty_first')]);
        }

        return DB::transaction(function () use ($ticket, $actor, $data, $photos, $approver, $fromAsset, $sheet) {
            if ($fromAsset !== null) {
                $this->checkWarranty->handle($ticket, $actor, $fromAsset['status'], $fromAsset['expires_on'], fromAsset: true);
            } elseif ($ticket->warranty_checked_at === null) {
                $this->checkWarranty->handle($ticket, $actor, $data['warranty_status'], null);
            }

            if (! empty($data['parts'])) {
                $customer = $ticket->customer_id ? collect($this->customers->handle(true))->firstWhere('id', $ticket->customer_id) : null;
                $this->requestParts->handle([
                    'id' => $ticket->id,
                    'ticket_no' => $ticket->ticket_no,
                    'contract_id' => $ticket->contract_id,
                    'customer_name' => $customer['name'] ?? null,
                ], $data['parts'], $actor);
            }

            foreach ($data['removed'] ?? [] as $piece) {
                TicketRemovedPart::create([
                    'ticket_id' => $ticket->id,
                    'asset_id' => $ticket->asset_id,
                    'item_name' => $piece['item_name'],
                    'serial_number' => filled($piece['serial_number'] ?? null) ? trim($piece['serial_number']) : null,
                    'problem' => $piece['problem'] ?? null,
                    'disposition' => $piece['disposition'],
                    'user_id' => $actor->id,
                    'user_name' => $actor->name,
                ]);
            }

            $cause = collect([
                $data['symptoms'] ? __('service.quick_close.symptoms').': '.implode(', ', $data['symptoms']) : null,
                $data['solutions'] ? __('service.quick_close.solutions').': '.implode(', ', $data['solutions']) : null,
                $data['note'] ?? null,
            ])->filter()->implode("\n");
            // Signed off on paper with nothing typed: what was done is written on the sheet.
            if ($cause === '' && $sheet !== null) {
                $cause = __('service.field_links.on_paper');
            }
            $this->saveReport->handle($ticket, $actor, ['cause' => $cause, 'extra_cost' => $ticket->extra_cost, 'approver_name' => $approver]);

            foreach (['before', 'after'] as $stage) {
                foreach ($photos[$stage] ?? [] as $file) {
                    $ticket->addMedia($file)->withCustomProperties(['stage' => $stage, 'by' => $actor->name])
                        ->toMediaCollection(Ticket::PHOTOS);
                }
            }

            if ($sheet !== null) {
                $ticket->addMedia($sheet)->withCustomProperties(['stage' => 'signed_sheet', 'by' => $actor->name, 'signer' => $approver])
                    ->toMediaCollection(Ticket::PHOTOS);
            } else {
                $png = base64_decode(substr($data['tech_signature'], strlen('data:image/png;base64,')), true);
                $ticket->addMediaFromString($png)->usingFileName('tech-signature.png')
                    ->withCustomProperties(['signer' => $actor->name, 'signed_at' => now()->toIso8601String(), 'by' => $actor->name])
                    ->toMediaCollection(Ticket::TECH_SIGNATURE);
                $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
                $ticket->addMediaFromString($png)->usingFileName('signature.png')
                    ->withCustomProperties(['signer' => $approver, 'signed_at' => now()->toIso8601String()])
                    ->toMediaCollection(Ticket::SIGNATURE);
            }

            $ticket->forceFill(['closed_lat' => $data['lat'] ?? null, 'closed_lng' => $data['lng'] ?? null])->save();

            $ticket = $ticket->fresh();
            if ($ticket->status !== Ticket::STATUS_IN_PROGRESS) {
                $ticket = $this->moveTicket->handle($ticket, 'start', $actor);
            }

            return $this->moveTicket->handle($ticket, 'resolve', $actor);
        });
    }
}
