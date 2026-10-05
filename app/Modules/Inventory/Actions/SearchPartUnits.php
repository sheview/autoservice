<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pieces of a part by status and serial (contains, ignoring case), newest first: the list on
 * the part page and the pickers that choose pieces to issue or take back.
 */
class SearchPartUnits
{
    /**
     * @param  array{status?: string|null, q?: string|null, ticket_id?: int|null, checkout_item_id?: int|null}  $filters
     * @return Builder<PartUnit>
     */
    public function handle(int $partId, array $filters = []): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return PartUnit::query()
            ->where('part_id', $partId)
            ->when(in_array($filters['status'] ?? null, PartUnit::STATUSES, true), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['ticket_id'] ?? null, fn (Builder $query, $id) => $query->where('ticket_id', $id))
            ->when($filters['checkout_item_id'] ?? null, fn (Builder $query, $id) => $query->where('checkout_item_id', $id))
            ->when($q !== '', fn (Builder $query) => $query->where('serial_number', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->orderByDesc('id');
    }
}
