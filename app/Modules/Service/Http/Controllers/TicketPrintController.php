<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Service\Actions\BlankTicketSheet;
use App\Modules\Service\Actions\TicketSheet;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The job sheet of a ticket: what was reported, what was done, the parts used and room for both
 * signatures. As an A4 page printed with the browser, or as a PDF (Gotenberg, Buddhist year).
 */
class TicketPrintController extends Controller
{
    public function show(Request $request, Ticket $ticket, TicketSheet $sheet): Response
    {
        Gate::authorize('view', $ticket);

        return Inertia::render('Service/Tickets/Print', $sheet->handle($ticket, $request->user()));
    }

    /** An empty job sheet to fill in by hand away from the system: staff who see tickets. */
    public function blank(Request $request, BlankTicketSheet $sheet, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        Gate::authorize('tickets.view');
        abort_if($request->user()->customer_id !== null, 403);

        try {
            return $renderPdf->handle('documents.ticket', $sheet->handle(), 'blank-ticket-form.pdf');
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    public function pdf(Request $request, Ticket $ticket, TicketSheet $sheet, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        Gate::authorize('view', $ticket);

        try {
            return $renderPdf->handle('documents.ticket', $sheet->handle($ticket, $request->user()), "{$ticket->ticket_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }
}
