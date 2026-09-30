<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\ReturnPartFromTicket;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Spare parts used on a ticket. Stock belongs to the Inventory module (its actions do the work);
 * these routes also need that module switched on.
 */
class TicketPartController extends Controller
{
    public const PERMISSION = 'stock.issue';

    /** Parts can still be booked right after the job is resolved, but not once it is closed or cancelled. */
    public const STATUSES = [...Ticket::OPEN_STATUSES, Ticket::STATUS_RESOLVED];

    /** Whoever may work on the ticket and may take parts out of stock. */
    public static function allows(User $user, Ticket $ticket): bool
    {
        return $user->can(self::PERMISSION) && $user->can('update', $ticket);
    }

    public function store(Request $request, Ticket $ticket, IssuePartToTicket $issuePart): RedirectResponse
    {
        $validated = $this->validated($request, $ticket);

        $issuePart->handle($ticket->id, $validated['part_id'], $validated['quantity'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', __('inventory.ticket_parts.issued'));
    }

    public function giveBack(Request $request, Ticket $ticket, ReturnPartFromTicket $returnPart): RedirectResponse
    {
        $validated = $this->validated($request, $ticket);

        $returnPart->handle($ticket->id, $validated['part_id'], $validated['quantity'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', __('inventory.ticket_parts.returned'));
    }

    /**
     * @return array{part_id: int, quantity: int, note?: string|null}
     */
    private function validated(Request $request, Ticket $ticket): array
    {
        abort_unless(self::allows($request->user(), $ticket), 403);

        if (! in_array($ticket->status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['part_id' => __('inventory.ticket_parts.ticket_closed')]);
        }

        return $request->validate([
            'part_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], attributes: __('inventory.fields'));
    }
}
