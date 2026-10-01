<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\CheckTicketWarranty;
use App\Modules\Service\Actions\CommentOnTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Actions\SaveRepairReport;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What people do on an open ticket: assign, check the warranty, move along the workflow, comment.
 */
class TicketActionController extends Controller
{
    public function assign(Request $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        Gate::authorize('assign', $ticket);

        $validated = $request->validate(
            ['assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')]],
            attributes: ['assignee_id' => __('service.fields.assignee_id')],
        );

        $assignTicket->handle($ticket, $validated['assignee_id'] ?? null, $request->user());

        return back()->with('success', __('service.tickets.assigned'));
    }

    public function move(Request $request, Ticket $ticket, MoveTicket $moveTicket): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(TicketWorkflow::ACTIONS))],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        Gate::authorize(TicketWorkflow::ACTIONS[$validated['action']]['ability'], $ticket);

        $moveTicket->handle($ticket, $validated['action'], $request->user(), $validated['comment'] ?? null);

        return back()->with('success', __("service.tickets.moved.{$validated['action']}"));
    }

    /**
     * The technician or helpdesk (whoever may work on the job, never the customer) records whether
     * the device is under warranty, while the job is open.
     */
    public function warranty(Request $request, Ticket $ticket, CheckTicketWarranty $checkWarranty): RedirectResponse
    {
        abort_unless(self::canCheckWarranty($request->user(), $ticket), 403);

        $validated = $request->validate([
            'warranty_status' => ['required', Rule::in(Ticket::WARRANTY_STATUSES)],
            'warranty_expires_on' => ['nullable', 'date'],
        ], attributes: __('service.fields'));

        $checkWarranty->handle($ticket, $request->user(), $validated['warranty_status'], $validated['warranty_expires_on'] ?? null);

        return back()->with('success', __('service.tickets.warranty_checked'));
    }

    /**
     * The repair report of the job sheet (cause, extra cost, approver), by whoever works on the job,
     * until it is closed.
     */
    public function report(Request $request, Ticket $ticket, SaveRepairReport $saveReport): RedirectResponse
    {
        abort_unless(self::canReport($request->user(), $ticket), 403);

        $validated = $request->validate([
            'cause' => ['nullable', 'string', 'max:5000'],
            'extra_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'approver_name' => ['nullable', 'string', 'max:255'],
        ], attributes: __('service.fields'));

        $saveReport->handle($ticket, $request->user(), [
            'cause' => $validated['cause'] ?? null,
            'extra_cost' => Money::toSatang($validated['extra_cost'] ?? null),
            'approver_name' => $validated['approver_name'] ?? null,
        ]);

        return back()->with('success', __('service.tickets.report_saved'));
    }

    public static function canReport(User $user, Ticket $ticket): bool
    {
        return $user->customer_id === null && $user->can('work', $ticket)
            && in_array($ticket->status, [...Ticket::OPEN_STATUSES, Ticket::STATUS_RESOLVED], true);
    }

    public static function canCheckWarranty(User $user, Ticket $ticket): bool
    {
        return $user->customer_id === null && $user->can('work', $ticket) && in_array($ticket->status, Ticket::OPEN_STATUSES, true);
    }

    public function comment(Request $request, Ticket $ticket, CommentOnTicket $commentOnTicket): RedirectResponse
    {
        Gate::authorize('comment', $ticket);

        $validated = $request->validate(
            ['body' => ['required', 'string', 'max:5000'], 'is_internal' => ['boolean']],
            attributes: ['body' => __('service.fields.comment')],
        );

        // Customer accounts cannot write internal notes (they would not see them either).
        $internal = $request->boolean('is_internal') && $request->user()->customer_id === null;
        $commentOnTicket->handle($ticket, $validated['body'], $internal, $request->user());

        return back()->with('success', __('service.tickets.commented'));
    }
}
