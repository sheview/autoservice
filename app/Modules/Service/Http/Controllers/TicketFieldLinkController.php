<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Actions\CreateFieldLink;
use App\Modules\Service\Actions\ReviewFieldReports;
use App\Modules\Service\Actions\RevokeFieldLink;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A ticket's links for working without an account (made, stopped, and what came back marked as
 * checked): by whoever may change the ticket (the helpdesk, the technician assigned).
 */
class TicketFieldLinkController extends Controller
{
    public function store(Request $request, Ticket $ticket, CreateFieldLink $create): RedirectResponse
    {
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'mode' => ['required', Rule::in(TicketFieldLink::MODES)],
            'holder_name' => ['required', 'string', 'max:255'],
            'holder_company' => ['nullable', 'string', 'max:255'],
            'holder_phone' => ['nullable', 'string', 'max:50'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.TicketFieldLink::MAX_DAYS],
        ], [], __('service.field_links.fields'));

        $create->handle($ticket, $data, $request->user());

        return back()->with('success', __('service.field_links.created'));
    }

    public function revoke(Request $request, Ticket $ticket, int $link, RevokeFieldLink $revoke): RedirectResponse
    {
        Gate::authorize('update', $ticket);
        $revoke->handle(TicketFieldLink::query()->where('ticket_id', $ticket->id)->findOrFail($link), $request->user());

        return back()->with('success', __('service.field_links.revoked'));
    }

    public function review(Request $request, Ticket $ticket, ReviewFieldReports $review): RedirectResponse
    {
        Gate::authorize('update', $ticket);
        $review->handle($ticket, $request->user());

        return back()->with('success', __('service.field_links.reviewed'));
    }
}
