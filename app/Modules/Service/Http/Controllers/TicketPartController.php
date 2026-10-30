<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ReturnPartFromTicket;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Spare parts used on a ticket. Stock belongs to the Inventory module (its actions do the work);
 * these routes also need that module switched on. Parts are taken out only through an issue
 * request for the ticket (approved, then handed out: Asset module); here they come back.
 */
class TicketPartController extends Controller
{
    public const PERMISSION = 'parts.issue';

    /** Parts can still be asked for right after the job is resolved, but not once it is closed or cancelled. */
    public const STATUSES = [...Ticket::OPEN_STATUSES, Ticket::STATUS_RESOLVED];

    /** Whoever may work on the ticket and handle its parts (TicketPolicy::issueParts): giving them back. */
    public static function allows(User $user, Ticket $ticket): bool
    {
        return $user->can('issueParts', $ticket);
    }

    /**
     * Parts come back whenever they do: a loan or a spare often returns after the job is closed.
     */
    public function giveBack(Request $request, Ticket $ticket, ReturnPartFromTicket $returnPart): RedirectResponse
    {
        abort_unless(self::allows($request->user(), $ticket), 403);

        $validated = $this->validated($request);

        $returnPart->handle($ticket->id, $validated['part_id'], $validated['quantity'], $request->user(), $validated['note'] ?? null, $validated['unit_ids'] ?? []);

        return back()->with('success', __('inventory.ticket_parts.returned'));
    }

    /**
     * @return array{part_id: int, quantity: int, note?: string|null, unit_ids?: list<int>}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'part_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
            // A part followed by serial number: the pieces (as many as the quantity).
            'unit_ids' => ['nullable', 'array', 'max:1000'],
            'unit_ids.*' => ['integer'],
        ], attributes: __('inventory.fields'));
    }
}
