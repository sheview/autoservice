<?php

namespace App\Modules\Service\Actions;

use App\Modules\Contract\Actions\SignatureRequirement;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketRemovedPart;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finishing a job on a phone in one go: what was wrong and what fixed it, photos before and
 * after, the parts used (taken from stock against the ticket; a part followed by serial number by
 * the pieces chosen, which go into the job's device), the pieces taken out of the device (a note),
 * the customer's signature when their contract or customer asks for it, and where the technician
 * was — then the job is done (resolved), which tells whoever confirms it (and the company's alert
 * channels) as usual. All or nothing: a part short of stock stops everything before any stock moves.
 */
class QuickCloseTicket
{
    public const CLOSABLE = [Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_ON_HOLD];

    public function __construct(
        private MoveTicket $moveTicket,
        private CheckTicketWarranty $checkWarranty,
        private SaveRepairReport $saveReport,
        private IssuePartToTicket $issuePart,
        private PartsForCheckout $parts,
        private SignatureRequirement $signatureRequirement,
    ) {}

    /**
     * @param  array{symptoms: list<string>, solutions: list<string>, note?: string|null, warranty_status?: string|null,
     *     parts?: list<array{part_id: int, qty: int, unit_ids?: list<int>}>, signer_name?: string|null, signature?: string|null,
     *     approver_name?: string|null, lat?: float|null, lng?: float|null,
     *     removed?: list<array{item_name: string, serial_number?: string|null, problem?: string|null, disposition: string}>}  $data  validated
     * @param  array{before?: list<UploadedFile>, after?: list<UploadedFile>}  $photos
     */
    public function handle(Ticket $ticket, User $actor, array $data, array $photos = []): Ticket
    {
        if (! in_array($ticket->status, self::CLOSABLE, true)) {
            throw ValidationException::withMessages(['ticket' => __('service.quick_close.not_closable')]);
        }

        $signatureNeeded = $this->signatureRequirement->handle($ticket->customer_id, $ticket->contract_id);
        if ($signatureNeeded && (blank($data['signature'] ?? null) || blank($data['signer_name'] ?? null))) {
            throw ValidationException::withMessages(['signature' => __('service.quick_close.signature_required')]);
        }
        $approver = $signatureNeeded ? $data['signer_name'] : ($data['signer_name'] ?? $data['approver_name'] ?? null);
        if (blank($approver)) {
            throw ValidationException::withMessages(['approver_name' => __('service.quick_close.approver_required')]);
        }
        if ($ticket->warranty_checked_at === null && blank($data['warranty_status'] ?? null)) {
            throw ValidationException::withMessages(['warranty_status' => __('service.tickets.warranty_first')]);
        }

        $this->checkStock($data['parts'] ?? []);

        return DB::transaction(function () use ($ticket, $actor, $data, $photos, $approver) {
            if ($ticket->warranty_checked_at === null) {
                $this->checkWarranty->handle($ticket, $actor, $data['warranty_status'], null);
            }

            foreach ($data['parts'] ?? [] as $line) {
                $this->issuePart->handle($ticket->id, (int) $line['part_id'], (int) $line['qty'], $actor, __('service.quick_close.part_note'),
                    unitIds: $line['unit_ids'] ?? [], links: ['asset_id' => $ticket->asset_id]);
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
            $this->saveReport->handle($ticket, $actor, ['cause' => $cause, 'extra_cost' => $ticket->extra_cost, 'approver_name' => $approver]);

            foreach (['before', 'after'] as $stage) {
                foreach ($photos[$stage] ?? [] as $file) {
                    $ticket->addMedia($file)->withCustomProperties(['stage' => $stage, 'by' => $actor->name])
                        ->toMediaCollection(Ticket::PHOTOS);
                }
            }

            if (filled($data['signature'] ?? null)) {
                $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
                $ticket->addMediaFromString($png)->usingFileName('signature.png')
                    ->withCustomProperties(['signer' => $data['signer_name'], 'signed_at' => now()->toIso8601String()])
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

    /**
     * Every part asked for must be in stock, all of them, before any stock moves; a part followed
     * by serial number needs a piece chosen for each one used.
     *
     * @param  list<array{part_id: int, qty: int, unit_ids?: list<int>}>  $lines
     */
    private function checkStock(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $wanted = collect($lines)->groupBy('part_id')->map(fn ($group) => $group->sum('qty'));
        $parts = $this->parts->handle($wanted->keys()->map(fn ($id) => (int) $id)->all());

        $short = $wanted->map(function (int $qty, $id) use ($parts) {
            $part = $parts[(int) $id] ?? null;

            return $part === null || ! $part['is_active'] || $part['qty_on_hand'] < $qty
                ? __('service.quick_close.short', ['name' => $part['name'] ?? '#'.$id, 'have' => $part['qty_on_hand'] ?? 0, 'unit' => $part['unit'] ?? ''])
                : null;
        })->filter();

        if ($short->isNotEmpty()) {
            throw ValidationException::withMessages(['parts' => $short->implode(' · ')]);
        }

        foreach ($lines as $line) {
            $part = $parts[(int) $line['part_id']];
            if ($part['track_serial'] && count(array_unique($line['unit_ids'] ?? [])) !== (int) $line['qty']) {
                throw ValidationException::withMessages(['parts' => __('inventory.units.pick_count', ['name' => $part['name'], 'count' => $line['qty']])]);
            }
        }
    }
}
