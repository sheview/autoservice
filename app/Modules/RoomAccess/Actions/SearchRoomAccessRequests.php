<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\ApprovalFlow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The request list: what the user may see (room-access.view; scope own = their own requests),
 * searched by number, purpose, requester or entrant, filtered by status (open / one status) and
 * room, sorted, newest first by default.
 */
class SearchRoomAccessRequests
{
    public const SORTS = ['planned_start', 'created_at', 'request_no'];

    /**
     * @return array{search: string, status: string, room: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $status = (string) $request->input('status', 'open');

        return [
            'search' => $request->string('search')->trim()->limit(100, '')->value(),
            'status' => in_array($status, ['all', 'awaiting'], true) || in_array($status, RoomAccessRequest::STATUSES, true) ? $status : 'open',
            'room' => $request->filled('room') ? (string) $request->input('room') : null,
            'sort' => in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : 'planned_start',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** Requests the user may see. */
    public static function visibleTo(Builder $query, User $user, string $permission = 'room-access.view'): Builder
    {
        return DataScope::constrain($query, $user, $permission, branch: null, customer: null,
            own: fn (Builder $q) => $q->where('requester_id', $user->id));
    }

    /**
     * Pending requests whose deciding step this user may decide (ApprovalFlow), by id.
     *
     * @return list<int>
     */
    private static function awaitingIds(User $user): array
    {
        return RoomAccessRequest::query()->where('status', RoomAccessRequest::STATUS_PENDING)->get()
            ->filter(fn (RoomAccessRequest $request) => ApprovalFlow::canDecide($user, $request))
            ->pluck('id')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RoomAccessRequest>
     */
    public function handle(User $user, array $filters, ?User $awaitingFor = null): Builder
    {
        // "awaiting": pending requests whose deciding step this user may decide (never their own).
        $awaiting = ($filters['status'] ?? null) === 'awaiting' && $awaitingFor !== null;
        $search = (string) ($filters['search'] ?? '');
        $like = '%'.addcslashes($search, '%_\\').'%';

        return self::visibleTo(RoomAccessRequest::query(), $user)
            ->with('room:id,ulid,name,customer_id')
            ->withCount('people')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('request_no', 'ilike', $like)->orWhere('purpose', 'ilike', $like)->orWhere('requester_name', 'ilike', $like)
                ->orWhereHas('people', fn (Builder $p) => $p->where('name', 'ilike', $like))))
            ->when(($filters['status'] ?? 'open') === 'open', fn (Builder $q) => $q->whereIn('status', RoomAccessRequest::OPEN_STATUSES))
            ->when(in_array($filters['status'] ?? null, RoomAccessRequest::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($awaiting, fn (Builder $q) => $q->whereIn('id', self::awaitingIds($awaitingFor)))
            ->when($filters['room'] ?? null, fn (Builder $q, string $ulid) => $q->whereHas('room', fn (Builder $r) => $r->where('ulid', $ulid)))
            ->orderBy(in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'planned_start', ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id');
    }
}
