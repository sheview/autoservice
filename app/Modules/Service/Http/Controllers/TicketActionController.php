<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\CommentOnTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * What people do on an open ticket: assign, move along the workflow, comment.
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
