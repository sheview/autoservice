<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\IssuableParts;
use App\Modules\Inventory\Actions\IssuePartToTicket;
use App\Modules\Inventory\Actions\ReturnPartFromTicket;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spare parts used on a ticket. Stock belongs to the Inventory module (its actions do the work);
 * these routes also need that module switched on.
 */
class TicketPartController extends Controller
{
    public const PERMISSION = 'parts.issue';

    /** Parts can still be taken right after the job is resolved, but not once it is closed or cancelled. */
    public const STATUSES = [...Ticket::OPEN_STATUSES, Ticket::STATUS_RESOLVED];

    /** Whoever may work on the ticket and may take parts out of stock for it (TicketPolicy::issueParts). */
    public static function allows(User $user, Ticket $ticket): bool
    {
        return $user->can('issueParts', $ticket);
    }

    public function store(Request $request, Ticket $ticket, IssuePartToTicket $issuePart): RedirectResponse
    {
        abort_unless(self::allows($request->user(), $ticket), 403);

        if (! in_array($ticket->status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['part_id' => __('inventory.ticket_parts.ticket_closed')]);
        }

        $validated = $this->validated($request);
        $type = $validated['type'] ?? IssuableParts::TYPES[0];

        $issuePart->handle($ticket->id, $validated['part_id'], $validated['quantity'], $request->user(), $validated['note'] ?? null, $type);

        return back()->with('success', __("inventory.ticket_parts.taken.{$type}"));
    }

    /**
     * Parts come back whenever they do: a loan or a spare often returns after the job is closed.
     */
    public function giveBack(Request $request, Ticket $ticket, ReturnPartFromTicket $returnPart): RedirectResponse
    {
        abort_unless(self::allows($request->user(), $ticket), 403);

        $validated = $this->validated($request);

        $returnPart->handle($ticket->id, $validated['part_id'], $validated['quantity'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', __('inventory.ticket_parts.returned'));
    }

    /**
     * @return array{part_id: int, quantity: int, type?: string|null, note?: string|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'part_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'type' => ['nullable', Rule::in(IssuableParts::TYPES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], attributes: __('inventory.fields'));
    }
}
