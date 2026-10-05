<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\RoomVisitor;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RequestHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for approval, only once the room's rules are accepted: the version in effect
 * now, exactly (a newer one since the popup opened must be read again). The acceptance is kept
 * as evidence with a copy of the text shown. Refused for a room that is closed, has no rules and
 * blocks, or is frozen at that time, and when an entrant's ID number is missing where the room
 * asks for it. The people entered are kept for the requester to pick next time (never their IDs).
 */
class SubmitRoomAccessRequest
{
    public function __construct(private RoomRulesForRequest $rules) {}

    /**
     * @param  array{accept?: bool, version_id?: int|null, ip?: string|null, user_agent?: string|null}  $acceptance
     */
    public function handle(RoomAccessRequest $request, User $actor, array $acceptance): RoomAccessRequest
    {
        return DB::transaction(function () use ($request, $actor, $acceptance) {
            $request = RoomAccessRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== RoomAccessRequest::STATUS_DRAFT) {
                throw ValidationException::withMessages(['request' => __('room_access.requests.not_draft')]);
            }
            $room = ServerRoom::query()->find($request->server_room_id);
            if ($room === null || ! $room->is_active) {
                throw ValidationException::withMessages(['server_room_id' => __('room_access.requests.room_closed')]);
            }
            $this->checkTimes($request, $room);
            $this->checkPeople($request, $room);

            $rules = $this->rules->handle($room, $actor);
            if ($rules['blocked']) {
                throw ValidationException::withMessages(['rules' => __('room_access.requests.no_rules')]);
            }
            $alreadyAccepted = $rules['accepted_before'] !== null;
            if (! $alreadyAccepted) {
                if (($acceptance['accept'] ?? false) !== true) {
                    throw ValidationException::withMessages(['accept' => __('room_access.requests.must_accept')]);
                }
                // The text accepted must be the text in effect now.
                if ((int) ($acceptance['version_id'] ?? 0) !== (int) $rules['version_id']) {
                    throw ValidationException::withMessages(['accept' => __('room_access.requests.rules_changed')]);
                }
                RoomRuleAcceptance::create([
                    'server_room_id' => $room->id,
                    'request_id' => $request->id,
                    'rule_version_id' => $rules['version_id'],
                    'version' => $rules['version'],
                    'context' => RoomRuleAcceptance::CONTEXT_SUBMIT,
                    'user_id' => $actor->id,
                    'accepted_by_name' => $actor->name,
                    'on_behalf_of_team' => $rules['team_note'],
                    'snapshot' => collect($rules)->only(['customer', 'room', 'version', 'effective_on', 'summary', 'company_terms', 'missing', 'team_note'])->all(),
                    'ip' => $acceptance['ip'] ?? null,
                    'user_agent' => isset($acceptance['user_agent']) ? mb_substr($acceptance['user_agent'], 0, 1000) : null,
                    'accepted_at' => now(),
                ]);
            }

            $from = $request->status;
            $request->fill([
                'status' => RoomAccessRequest::STATUS_PENDING,
                'rule_version_id' => $rules['version_id'],
                'submitted_at' => now(),
                'decision_note' => null,
            ])->save();
            RequestHistory::record($request, 'submitted', $from, $actor,
                $alreadyAccepted ? __('room_access.requests.accepted_before', ['version' => $rules['version']]) : null);

            $this->rememberPeople($request, $actor);

            return $request;
        });
    }

    private function checkTimes(RoomAccessRequest $request, ServerRoom $room): void
    {
        if ($request->planned_end->isPast()) {
            throw ValidationException::withMessages(['planned_end' => __('room_access.requests.in_the_past')]);
        }
        foreach ($room->freeze_periods ?? [] as $period) {
            $from = CarbonImmutable::parse($period['from']);
            $to = CarbonImmutable::parse($period['to']);
            if ($request->planned_start->lt($to) && $request->planned_end->gt($from)) {
                throw ValidationException::withMessages(['planned_start' => __('room_access.requests.frozen', [
                    'from' => $from->format('d/m/Y H:i'), 'to' => $to->format('d/m/Y H:i'), 'reason' => $period['reason'] ?? '-',
                ])]);
            }
        }
    }

    private function checkPeople(RoomAccessRequest $request, ServerRoom $room): void
    {
        $people = $request->people()->get();
        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['people' => __('room_access.requests.people_required')]);
        }
        if ($room->requires_id_number && $people->contains(fn (RoomAccessPerson $p) => blank($p->id_number))) {
            throw ValidationException::withMessages(['people' => __('room_access.requests.id_required')]);
        }
    }

    private function rememberPeople(RoomAccessRequest $request, User $actor): void
    {
        foreach ($request->people()->get() as $person) {
            $visitor = RoomVisitor::query()->where('owner_id', $actor->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($person->name)])
                ->whereRaw("lower(coalesce(company, '')) = ?", [mb_strtolower((string) $person->company)])
                ->first() ?? new RoomVisitor(['owner_id' => $actor->id, 'name' => $person->name, 'company' => $person->company]);
            $visitor->fill(['phone' => $person->phone ?? $visitor->phone, 'last_used_at' => now()])->save();
        }
    }
}
