<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RequestHistory;
use App\Modules\RoomAccess\Support\RoomSchedule;
use App\Modules\RoomAccess\Support\RoomVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that the team went in (an approved request, from an hour before the planned start until
 * the planned end; on a standing request, on its days from an hour before its hours start): by a signed-in user, or by a guard through the request's guard link (named).
 * When the room asks for the rules to be accepted again on entering, that acceptance is kept as
 * evidence too (by the user, or confirmed by the guard for the team).
 */
class RecordRoomEntry
{
    public function __construct(private RoomRulesForRequest $rules) {}

    /**
     * @param  array{accept?: bool, ip?: string|null, user_agent?: string|null}  $context
     */
    public function handle(RoomAccessRequest $request, ?User $actor, ?string $guardName = null, array $context = []): RoomAccessRequest
    {
        return DB::transaction(function () use ($request, $actor, $guardName, $context) {
            $request = RoomAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== RoomAccessRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['visit' => __('room_access.visit.not_approved')]);
            }
            if (now()->lt($request->planned_start->copy()->subMinutes(RoomVisit::EARLY_MINUTES))) {
                throw ValidationException::withMessages(['visit' => __('room_access.visit.too_early', ['minutes' => RoomVisit::EARLY_MINUTES])]);
            }
            if ($request->planned_end->isPast()) {
                throw ValidationException::withMessages(['visit' => __('room_access.visit.too_late')]);
            }
            // A standing request: only on its days, within its hours.
            if ($request->isRecurring() && RoomSchedule::slotAt($request, now(), RoomVisit::EARLY_MINUTES) === null) {
                throw ValidationException::withMessages(['visit' => __('room_access.visit.outside_schedule', ['schedule' => RoomSchedule::describe($request)])]);
            }

            $room = ServerRoom::withTrashed()->findOrFail($request->server_room_id);
            if ($room->accept_on_enter) {
                if (($context['accept'] ?? false) !== true) {
                    throw ValidationException::withMessages(['accept' => __('room_access.visit.accept_on_enter')]);
                }
                $rules = $this->rules->handle($room);
                RoomRuleAcceptance::create([
                    'server_room_id' => $room->id,
                    'request_id' => $request->id,
                    'rule_version_id' => $rules['version_id'],
                    'version' => $rules['version'],
                    'context' => RoomRuleAcceptance::CONTEXT_ENTER,
                    'user_id' => $actor?->id,
                    'accepted_by_name' => $actor?->name ?? __('room_access.visit.guard_for_team', ['name' => $guardName]),
                    'on_behalf_of_team' => true,
                    'snapshot' => collect($rules)->only(['customer', 'room', 'version', 'effective_on', 'summary', 'company_terms', 'missing', 'team_note'])->all(),
                    'ip' => $context['ip'] ?? null,
                    'user_agent' => isset($context['user_agent']) ? mb_substr($context['user_agent'], 0, 1000) : null,
                    'accepted_at' => now(),
                ]);
            }

            $by = $actor?->name ?? __('room_access.visit.by_guard', ['name' => $guardName]);
            $request->fill(['status' => RoomAccessRequest::STATUS_INSIDE, 'entered_at' => now(), 'entered_by_name' => $by,
                'exited_at' => null, 'exited_by_name' => null])->save();
            $request->visits()->create(['entered_at' => now(), 'entered_by_name' => $by]);
            RequestHistory::record($request, 'entered', RoomAccessRequest::STATUS_APPROVED, $actor, $actor ? null : $by, $by);

            return $request;
        });
    }
}
