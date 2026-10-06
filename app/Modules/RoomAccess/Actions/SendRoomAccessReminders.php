<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Platform\Support\AlertSettings;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RoomAccessAlert;
use App\Modules\RoomAccess\Support\RoomSchedule;
use App\Modules\RoomAccess\Support\RoomVisit;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Reminders, in the current company, each sent once (where the company set its alerts):
 *  - room_access_starting_soon: an approved request starts within SOON_MINUTES (on a standing
 *    request, each day it is used);
 *  - room_access_overstay: the team is still inside after the planned end (once per visit);
 *  - room_access_approval_overdue: a request waits for approval longer than the company's
 *    approval_hours (once per sending).
 */
class SendRoomAccessReminders
{
    public const SOON_MINUTES = 60;

    public function __construct(private TenantContext $context) {}

    /** @return array{starting: int, overstay: int, approval: int} how many of each went out */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $sent = ['starting' => 0, 'overstay' => 0, 'approval' => 0];

        RoomAccessRequest::query()->where('status', RoomAccessRequest::STATUS_APPROVED)
            ->where('planned_start', '<=', $now->addMinutes(self::SOON_MINUTES))->where('planned_end', '>', $now)
            ->get()->each(function (RoomAccessRequest $request) use ($now, &$sent) {
                $next = collect(RoomSchedule::slots($request, $now, $now->addMinutes(self::SOON_MINUTES)))
                    ->first(fn (array $slot) => $slot[0]->gt($now) && $slot[0]->lte($now->addMinutes(self::SOON_MINUTES)));
                // Not yet reminded of this time.
                if ($next === null || ($request->reminded_at && $request->reminded_at->gte($next[0]->subMinutes(self::SOON_MINUTES)))) {
                    return;
                }
                $request->forceFill(['reminded_at' => $now])->save();
                RoomAccessAlert::send('room_access_starting_soon', $request, null, $next[0]->format('d/m/Y H:i').' - '.$next[1]->format('H:i'));
                $sent['starting']++;
            });

        RoomAccessRequest::query()->where('status', RoomAccessRequest::STATUS_INSIDE)->get()
            ->each(function (RoomAccessRequest $request) use ($now, &$sent) {
                $end = RoomVisit::visitEnd($request);
                if ($end->gte($now) || ($request->overstay_alerted_at && $request->entered_at && $request->overstay_alerted_at->gte($request->entered_at))) {
                    return;
                }
                $request->forceFill(['overstay_alerted_at' => $now])->save();
                RoomAccessAlert::send('room_access_overstay', $request, $request->entered_by_name,
                    __('room_access.visit.left_late', ['minutes' => (int) $end->diffInMinutes($now)]));
                $sent['overstay']++;
            });

        $hours = AlertSettings::thresholds($this->context->tenant())['approval_hours'];
        if ($hours > 0) {
            RoomAccessRequest::query()->where('status', RoomAccessRequest::STATUS_PENDING)
                ->where('submitted_at', '<=', $now->subHours($hours))
                ->where(fn ($q) => $q->whereNull('approval_alerted_at')->orWhereColumn('approval_alerted_at', '<', 'submitted_at'))
                ->get()->each(function (RoomAccessRequest $request) use ($now, $hours, &$sent) {
                    $request->forceFill(['approval_alerted_at' => $now])->save();
                    RoomAccessAlert::send('room_access_approval_overdue', $request, null, (string) $hours);
                    $sent['approval']++;
                });
        }

        return $sent;
    }
}
