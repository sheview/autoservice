<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\PublicTicketStatus;
use App\Modules\Service\Support\TrackingToken;

/**
 * The customer's view of the ticket of a tracking link, in the current company only (a token of
 * another company is simply not found here), or null.
 */
class TicketByToken
{
    /**
     * @return array{ticket_no: string, state: string, step: int, updated_at: string, message: string|null}|null
     */
    public function handle(string $token): ?array
    {
        // Same work for a malformed token as for an unknown one.
        $ticket = Ticket::query()->where('tracking_token', TrackingToken::looksValid($token) ? $token : '-')->first();

        return $ticket ? PublicTicketStatus::of($ticket) : null;
    }
}
