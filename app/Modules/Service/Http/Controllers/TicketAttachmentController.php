<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to a ticket (Word, Excel, PDF). Whoever may see the ticket opens them; whoever
 * may comment on it (customer accounts included) adds more; whoever may edit it deletes them.
 */
class TicketAttachmentController extends Controller
{
    use ServesAttachments;

    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('comment', $ticket);

        return $this->storeAttachments($request, $ticket);
    }

    public function show(Ticket $ticket, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $ticket);

        return $this->showAttachment($ticket, $attachment);
    }

    public function destroy(Ticket $ticket, int $attachment): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        return $this->destroyAttachment($ticket, $attachment);
    }
}
