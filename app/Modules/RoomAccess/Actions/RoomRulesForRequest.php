<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomAccessSettings;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the accept popup shows for one room, and only that room: the customer's rules in effect
 * (version, lines, link to the full document) followed by the company's own terms. Without rules
 * the room is blocked, or (as the room says) the company's terms are shown with a warning. With
 * "once per version" a user who has accepted this version before need not again; a request sent
 * back for more information that already accepted this version need not either, whatever the mode.
 */
class RoomRulesForRequest
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{room: string, customer: string, version_id: int|null, version: int|null, effective_on: string|null,
     *     summary: list<string>, company_terms: list<string>, file_url: string|null, missing: bool, blocked: bool,
     *     accepted_before: string|null, team_note: bool}
     */
    public function handle(ServerRoom $room, ?User $user = null, ?RoomAccessRequest $request = null): array
    {
        $rules = $room->currentRules()->first();
        $missing = $rules === null;
        $acceptedBefore = null;
        if ($rules !== null && $user !== null && $room->accept_mode === ServerRoom::ACCEPT_ONCE_PER_VERSION) {
            $acceptedBefore = RoomRuleAcceptance::query()->where('user_id', $user->id)->where('rule_version_id', $rules->id)
                ->orderByDesc('accepted_at')->value('accepted_at');
        }
        if ($rules !== null && $acceptedBefore === null && $request?->exists) {
            $acceptedBefore = RoomRuleAcceptance::query()->where('request_id', $request->id)->where('rule_version_id', $rules->id)
                ->where('context', RoomRuleAcceptance::CONTEXT_SUBMIT)->orderByDesc('accepted_at')->value('accepted_at');
        }

        return [
            'room' => $room->name,
            'customer' => app(CustomerLabelNames::class)->handle()[$room->customer_id] ?? '-',
            'version_id' => $rules?->id,
            'version' => $rules?->version,
            'effective_on' => $rules?->effective_on->toDateString(),
            'summary' => $rules?->summary ?? [],
            'company_terms' => RoomAccessSettings::of($this->context->tenant())['company_terms'],
            'file_url' => $rules?->hasMedia(RoomRuleVersion::FILE) ? route('room-access.requests.rules-file', [$room->ulid, $rules->id]) : null,
            'missing' => $missing,
            'blocked' => $missing && $room->missing_rules === ServerRoom::MISSING_BLOCK,
            'accepted_before' => $acceptedBefore === null ? null : (string) $acceptedBefore,
            // The requester accepts for the whole team (unless each entrant accepts through a link).
            'team_note' => ! $room->entrants_accept_self,
        ];
    }
}
