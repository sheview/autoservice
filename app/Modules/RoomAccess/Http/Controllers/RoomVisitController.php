<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RoomAccess\Actions\FinishRoomVisit;
use App\Modules\RoomAccess\Actions\IssueRoomAccessToken;
use App\Modules\RoomAccess\Actions\RecordRoomEntry;
use App\Modules\RoomAccess\Actions\RecordRoomExit;
use App\Modules\RoomAccess\Actions\SendGuardLink;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Support\RoomVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The visit itself, signed in: going in (accepting the rules again when the room asks), coming
 * out (the requester or a caretaker of the room), the work summary afterwards (the requester),
 * and a new guard link for the room's guards.
 */
class RoomVisitController extends Controller
{
    public function enter(Request $request, RoomAccessRequest $roomRequest, RecordRoomEntry $enter): RedirectResponse
    {
        $this->authorizeRecord($request, $roomRequest);
        $data = $request->validate(['accept' => ['nullable', 'boolean']]);
        $enter->handle($roomRequest, $request->user(), null, ['accept' => (bool) ($data['accept'] ?? false), 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return back()->with('success', __('room_access.visit.entered'));
    }

    public function exit(Request $request, RoomAccessRequest $roomRequest, RecordRoomExit $exit): RedirectResponse
    {
        $this->authorizeRecord($request, $roomRequest);
        $exit->handle($roomRequest, $request->user());

        return back()->with('success', __('room_access.visit.exited'));
    }

    public function finish(Request $request, RoomAccessRequest $roomRequest, FinishRoomVisit $finish): RedirectResponse
    {
        Gate::authorize('view', $roomRequest);
        $data = $request->validate([
            'work_summary' => ['required', 'string', 'max:5000'],
            'items_confirmed' => ['nullable', 'boolean'],
        ], [], __('room_access.fields'));
        $finish->handle($roomRequest, $request->user(), $data['work_summary'], (bool) ($data['items_confirmed'] ?? false));

        return back()->with('success', __('room_access.visit.finished'));
    }

    /** A new guard link (the old one stops working), e-mailed again to the room's guards. */
    public function renewGuardLink(Request $request, RoomAccessRequest $roomRequest, IssueRoomAccessToken $issue, SendGuardLink $send): RedirectResponse
    {
        Gate::authorize('view', $roomRequest);
        abort_unless((int) $roomRequest->requester_id === $request->user()->id || $request->user()->can('room-access.approve'), 403);
        abort_unless($roomRequest->room?->guard_link && in_array($roomRequest->status, [RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE], true), 404);

        $send->handle($roomRequest, $issue->handle($roomRequest, RoomAccessToken::GUARD, $request->user()));

        return back()->with('success', __('room_access.visit.guard_link_renewed'));
    }

    private function authorizeRecord(Request $request, RoomAccessRequest $roomRequest): void
    {
        Gate::authorize('view', $roomRequest);
        abort_unless(RoomVisit::canRecord($request->user(), $roomRequest), 403);
    }
}
