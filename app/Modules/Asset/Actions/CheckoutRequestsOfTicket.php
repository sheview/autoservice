<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestRow;
use App\Modules\Identity\Models\User;

/**
 * The issue/loan requests for a ticket (the request's or one of its lines'), newest first, as
 * plain rows for the ticket page (Service module); only those the user may see, drafts their own.
 */
class CheckoutRequestsOfTicket
{
    /** @return list<array<string, mixed>> */
    public function handle(int $ticketId, User $user): array
    {
        return SearchCheckoutRequests::visibleTo(CheckoutRequest::query(), $user)
            ->with('items')
            ->where(fn ($q) => $q->where('ticket_id', $ticketId)->orWhereHas('items', fn ($q) => $q->where('ticket_id', $ticketId)))
            ->where(fn ($q) => $q->where('status', '!=', CheckoutRequest::STATUS_DRAFT)->orWhere('requester_id', $user->id))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (CheckoutRequest $request) => RequestRow::of($request))
            ->all();
    }
}
