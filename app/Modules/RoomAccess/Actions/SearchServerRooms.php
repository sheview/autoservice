<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Database\Eloquent\Builder;

/**
 * The room list query: search (name, location), customer, with or without rules yet, and sort;
 * each room with its rules in effect and how many versions it has had.
 */
class SearchServerRooms
{
    public const SORTS = ['name', 'updated_at'];

    /**
     * @param  array{search?: string, customer_id?: int|null, rules?: string|null, sort?: string, direction?: string}  $filters
     * @return Builder<ServerRoom>
     */
    public function handle(array $filters): Builder
    {
        $like = '%'.addcslashes((string) ($filters['search'] ?? ''), '%_\\').'%';

        return ServerRoom::query()
            ->with('currentRules')
            ->withCount('ruleVersions')
            ->when(($filters['search'] ?? '') !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'ilike', $like)->orWhere('location', 'ilike', $like)))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, int $id) => $q->where('customer_id', $id))
            ->when(($filters['rules'] ?? null) === 'missing', fn (Builder $q) => $q->whereDoesntHave('ruleVersions'))
            ->when(($filters['rules'] ?? null) === 'set', fn (Builder $q) => $q->whereHas('ruleVersions'))
            ->orderBy(in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'name', ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }
}
