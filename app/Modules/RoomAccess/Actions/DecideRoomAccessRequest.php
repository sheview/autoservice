<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessApproval;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Support\ApprovalFlow;
use App\Modules\RoomAccess\Support\RequestHistory;
use App\Modules\RoomAccess\Support\RoomAccessAlert;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An approver's decision on the step of a pending request that decides now:
 *
 *   approve  the step is done; the last step approves the request
 *   reject   the request is turned down, with why
 *   ask      sent back to the requester as a draft, with what is missing (they send it again,
 *            accepting the rules again, and the approval starts over)
 *
 * Never the requester's own request, never a step out of turn (ApprovalFlow).
 */
class DecideRoomAccessRequest
{
    public const DECISIONS = ['approve', 'reject', 'ask'];

    public function __construct(
        private IssueRoomAccessToken $issueToken,
        private RevokeRoomAccessTokens $revokeTokens,
    ) {}

    public function handle(RoomAccessRequest $request, string $decision, User $actor, ?string $note = null): RoomAccessRequest
    {
        if (in_array($decision, ['reject', 'ask'], true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __("room_access.approvals.note_required_{$decision}")]);
        }

        $request = DB::transaction(function () use ($request, $decision, $actor, $note) {
            $request = RoomAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== RoomAccessRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['decision' => __('room_access.approvals.not_pending')]);
            }
            if ((int) $request->requester_id === $actor->id) {
                throw new AuthorizationException(__('room_access.approvals.own_request'));
            }
            if (! ApprovalFlow::canDecide($actor, $request)) {
                throw new AuthorizationException(__('room_access.approvals.not_your_step'));
            }
            $step = ApprovalFlow::current($request);

            RoomAccessApproval::create([
                'request_id' => $request->id,
                'step' => $step['position'],
                'side' => $step['side'],
                'decision' => match ($decision) {
                    'approve' => RoomAccessApproval::APPROVED,
                    'reject' => RoomAccessApproval::REJECTED,
                    'ask' => RoomAccessApproval::ASKED,
                },
                'note' => $note,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'round' => $request->round,
                'decided_at' => now(),
            ]);

            $from = $request->status;
            if ($decision === 'approve') {
                $done = ApprovalFlow::current($request) === null;
                if ($done) {
                    $request->fill(['status' => RoomAccessRequest::STATUS_APPROVED, 'approved_at' => now(), 'decision_note' => $note])->save();
                    // The permit's link, for its QR and the guard's counter.
                    $this->issueToken->handle($request, RoomAccessToken::PERMIT, $actor);
                }
                RequestHistory::record($request, $done ? 'approved' : 'step_approved', $from, $actor, $note);
            } else {
                $request->fill([
                    'status' => $decision === 'reject' ? RoomAccessRequest::STATUS_REJECTED : RoomAccessRequest::STATUS_DRAFT,
                    'decision_note' => $note,
                ])->save();
                RequestHistory::record($request, $decision === 'reject' ? 'rejected' : 'info_requested', $from, $actor, $note);
                $this->revokeTokens->handle($request, $actor);
            }

            return $request;
        });

        RoomAccessAlert::send(match (true) {
            $request->status === RoomAccessRequest::STATUS_APPROVED => 'room_access_approved',
            $request->status === RoomAccessRequest::STATUS_REJECTED => 'room_access_rejected',
            $request->status === RoomAccessRequest::STATUS_DRAFT => 'room_access_info_requested',
            default => 'room_access_requested', // the next step's approvers
        }, $request, $actor->name, $note);

        return $request;
    }
}
