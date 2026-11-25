<?php

namespace App\Modules\Service\Actions;

use App\Modules\Platform\Actions\SendAlert;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Service\Models\TicketFieldLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What comes back through a ticket's link, without an account:
 *
 *   work  the outside technician's report (what was wrong, what was done, parts they used, a
 *         note), photos before / after, and always a sign-off, one of:
 *           - on paper: the printed job sheet the customer filled in and signed, as a photo;
 *           - on the screen: what was done (a solution at least), the technician's signature,
 *             then the customer's.
 *   sign  the customer's signature on the screen only
 *
 * It fills the ticket's repair report and signature (the customer who signed is who approved the
 * repair), but never moves the job: the helpdesk checks it, then closes and prints as usual.
 * Kept in the ticket's history with the link holder's name; the company's alerts hear of it.
 */
class SubmitFieldReport
{
    public function __construct(private RecordTicketEvent $recordEvent) {}

    /**
     * @param  array{symptoms?: list<string>, solutions?: list<string>, parts?: string|null, note?: string|null,
     *     signer_name?: string|null, signature?: string|null, tech_signature?: string|null}  $data  validated
     * @param  array{before?: list<UploadedFile>, after?: list<UploadedFile>, signed_sheet?: UploadedFile|null}  $files
     */
    public function handle(TicketFieldLink $link, array $data, array $files = []): Ticket
    {
        if (! $link->usable()) {
            throw ValidationException::withMessages(['link' => __('service.field_links.unusable')]);
        }
        $work = $link->mode === TicketFieldLink::MODE_WORK;
        $signature = filled($data['signature'] ?? null);
        $sheet = $work ? ($files['signed_sheet'] ?? null) : null;

        if (! $work && ! $signature) {
            throw ValidationException::withMessages(['signature' => __('service.field_links.signature_required')]);
        }
        // The work is sent with its sign-off only: the paper sheet, or both signatures on the screen.
        $techSignature = $work && $sheet === null && filled($data['tech_signature'] ?? null);
        if ($work && $sheet === null) {
            $missing = array_filter([
                'solutions' => ($data['solutions'] ?? []) === [] ? __('service.field_links.solution_required') : null,
                'tech_signature' => ! $techSignature ? __('service.field_links.tech_signature_required') : null,
                'signature' => ! $signature ? __('service.field_links.customer_signature_required') : null,
            ]);
            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }
        if (($signature || $sheet) && blank($data['signer_name'] ?? null)) {
            throw ValidationException::withMessages(['signer_name' => __('service.field_links.signer_required')]);
        }
        $cause = $work ? collect([
            ($data['symptoms'] ?? []) ? __('service.quick_close.symptoms').': '.implode(', ', $data['symptoms']) : null,
            ($data['solutions'] ?? []) ? __('service.quick_close.solutions').': '.implode(', ', $data['solutions']) : null,
            filled($data['parts'] ?? null) ? __('service.field_links.parts_line', ['parts' => $data['parts']]) : null,
            $data['note'] ?? null,
        ])->filter()->implode("\n") : null;
        // Signed off on paper with nothing typed: what was done is written on the sheet.
        if ($work && $cause === '' && $sheet !== null) {
            $cause = __('service.field_links.on_paper');
        }

        $by = __('service.field_links.by', ['name' => $link->holder_name, 'company' => $link->holder_company ?? '-']);

        $ticket = DB::transaction(function () use ($link, $data, $files, $work, $signature, $techSignature, $sheet, $cause, $by) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($link->ticket_id);

            if ($work && $cause !== '') {
                $ticket->cause = $cause;
            }
            if ($signature || $sheet) {
                // The customer who signed off is who approved the repair (the report needs one).
                $ticket->approver_name = trim($data['signer_name']);
            }
            $ticket->save();

            foreach (['before', 'after'] as $stage) {
                foreach ($work ? ($files[$stage] ?? []) : [] as $file) {
                    $ticket->addMedia($file)->withCustomProperties(['stage' => $stage, 'by' => $by])->toMediaCollection(Ticket::PHOTOS);
                }
            }
            if ($sheet !== null) {
                $ticket->addMedia($sheet)->withCustomProperties(['stage' => 'signed_sheet', 'by' => $by, 'signer' => $data['signer_name']])
                    ->toMediaCollection(Ticket::PHOTOS);
            }
            if ($techSignature) {
                $png = base64_decode(substr($data['tech_signature'], strlen('data:image/png;base64,')), true);
                $ticket->addMediaFromString($png)->usingFileName('tech-signature.png')
                    ->withCustomProperties(['signer' => $link->holder_name, 'signed_at' => now()->toIso8601String(), 'by' => $by])
                    ->toMediaCollection(Ticket::TECH_SIGNATURE);
            }
            if ($signature) {
                $png = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
                $ticket->addMediaFromString($png)->usingFileName('signature.png')
                    ->withCustomProperties(['signer' => $data['signer_name'], 'signed_at' => now()->toIso8601String(), 'by' => $by])
                    ->toMediaCollection(Ticket::SIGNATURE);
            }

            $this->recordEvent->handle($ticket, TicketEvent::TYPE_UPDATED, null, [
                'user_name' => $by,
                'body' => $work ? __('service.field_links.submitted_event', ['cause' => $cause ?: '-']) : __('service.field_links.signed_event', ['name' => $data['signer_name']]),
                'is_internal' => true,
            ]);
            $link->update(['submitted_at' => now(), 'reviewed_at' => null, 'reviewed_by_name' => null]);

            return $ticket;
        });

        app(SendAlert::class)->handle('ticket_field_reported', [
            'no' => $ticket->ticket_no,
            'title' => $ticket->title,
            'actor' => $by,
        ], route('service.tickets.show', $ticket));

        return $ticket;
    }
}
