<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RoomAccessSettings;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the entrants' ID card numbers of requests that ended (left the room, or were cancelled,
 * turned down or overdue) longer ago than the company keeps them (RoomAccessSettings), and notes
 * when on the request. Names and companies stay. One line in the activity log per run.
 */
class PurgeEntrantIdNumbers
{
    public const ENDED = [RoomAccessRequest::STATUS_EXITED, RoomAccessRequest::STATUS_CANCELLED, RoomAccessRequest::STATUS_REJECTED, RoomAccessRequest::STATUS_OVERDUE];

    public function handle(Tenant $tenant): int
    {
        $before = now()->subDays(RoomAccessSettings::of($tenant)['id_retention_days']);

        $requests = RoomAccessRequest::withTrashed()
            ->whereIn('status', self::ENDED)
            ->whereNull('id_numbers_purged_at')
            ->where(fn ($q) => $q->where('exited_at', '<', $before)->orWhere(fn ($w) => $w->whereNull('exited_at')->where('updated_at', '<', $before)))
            ->whereHas('people', fn ($q) => $q->whereNotNull('id_number'))
            ->get();

        foreach ($requests as $request) {
            DB::transaction(function () use ($request) {
                RoomAccessPerson::query()->where('request_id', $request->id)->update(['id_number' => null]);
                $request->forceFill(['id_numbers_purged_at' => now()])->saveQuietly();
            });
        }

        if ($requests->isNotEmpty()) {
            activity()->performedOn($tenant)->event('room_access_ids_purged')
                ->withProperties(['requests' => $requests->pluck('request_no')->all(), 'days' => RoomAccessSettings::of($tenant)['id_retention_days']])
                ->log(__('room_access.log.ids_purged', ['count' => $requests->count()]));
        }

        return $requests->count();
    }
}
