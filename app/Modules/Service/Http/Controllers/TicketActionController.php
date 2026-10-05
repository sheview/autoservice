<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\CrossTenant\ForwardedTickets;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\CheckTicketWarranty;
use App\Modules\Service\Actions\CommentOnTicket;
use App\Modules\Service\Actions\LinkTicketIp;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Actions\RenewTrackingToken;
use App\Modules\Service\Actions\ReviewReportedTicket;
use App\Modules\Service\Actions\SaveRepairReport;
use App\Modules\Service\Actions\SetTicketAppointment;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\TicketWorkflow;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    /** Which IP address the ticket is about (IP management), or none: staff who may update the ticket. */
    public function ip(Request $request, Ticket $ticket, LinkTicketIp $linkTicketIp): RedirectResponse
    {
        abort_unless($request->user()->customer_id === null && $request->user()->can('update', $ticket), 403);

        $validated = $request->validate(['ip' => ['nullable', 'string', 'max:40']]);
        $linkTicketIp->handle($ticket, $validated['ip'] ?? null);

        return back()->with('success', __('service.tickets.ip_saved'));
    }

    /** The helpdesk's answer to a problem a customer reported with a QR code (tickets.assign). */
    public function review(Request $request, Ticket $ticket, ReviewReportedTicket $review): RedirectResponse
    {
        Gate::authorize('assign', $ticket);
        $data = $request->validate([
            'decision' => ['required', Rule::in(ReviewReportedTicket::DECISIONS)],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $review->handle($ticket, $data['decision'], $data['message'] ?? null, $request->user());

        return back()->with('success', __("service.reported.done_{$data['decision']}"));
    }

    /** A photo taken on the job (reported, before, after) or the customer's signature: who may see the ticket. */
    public function media(Ticket $ticket, int $media): BinaryFileResponse
    {
        Gate::authorize('view', $ticket);
        $file = $ticket->media()->whereKey($media)->whereIn('collection_name', [Ticket::PHOTOS, Ticket::SIGNATURE])->firstOrFail();

        return response()->file($file->getPath(), ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, max-age=3600']);
    }

    /** A new tracking link for the customer (the old one stops working): who may update the ticket. */
    public function trackingToken(Request $request, Ticket $ticket, RenewTrackingToken $renew): RedirectResponse
    {
        Gate::authorize('update', $ticket);
        $renew->handle($ticket, $request->user());

        return back()->with('success', __('service.tickets.tracking_renewed'));
    }

    /** When the technician is due on site (or none): puts the job on that day of their calendar. */
    public function appointment(Request $request, Ticket $ticket, SetTicketAppointment $setAppointment): RedirectResponse
    {
        Gate::authorize('update', $ticket);
        $validated = $request->validate(['appointment_at' => ['nullable', 'date']], attributes: __('service.fields'));

        $at = filled($validated['appointment_at'] ?? null)
            ? CarbonImmutable::parse($validated['appointment_at'], config('app.timezone'))->toDateTimeString()
            : null;
        $setAppointment->handle($ticket, $at, $request->user());

        return back()->with('success', __('service.tickets.appointment_saved'));
    }

    /** Sends the job on to another company that takes tickets from us (cross-company sharing). */
    public function forward(Request $request, Ticket $ticket, ForwardedTickets $forwarded): RedirectResponse
    {
        abort_unless($request->user()->customer_id === null && $request->user()->can('update', $ticket), 403);

        $validated = $request->validate([
            'company' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $target = Tenant::query()->where('is_platform', false)->find($validated['company']);
        abort_if($target === null, 404);

        $link = $forwarded->forward($request->user(), $target, $ticket, $validated['note'] ?? null);

        return back()->with('success', __('service.tickets.forwarded', ['company' => $target->name, 'no' => $link->target_label]));
    }

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
