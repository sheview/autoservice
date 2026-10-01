<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The stock ledger query: search (part, reference, person) + filters (type, part) + sort.
 */
class SearchStockMovements
{
    public const SORTABLE = ['created_at', 'quantity'];

    /**
     * @return array{search: string, type: string|null, part_id: int|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'type' => in_array($request->input('type'), StockMovement::TYPES, true) ? $request->input('type') : null,
            'part_id' => $request->integer('part_id') ?: null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @param  User|null  $user  the viewer: only the rows within reach of their stock-movements.view; null = all
     * @return Builder<StockMovement>
     */
    public function handle(array $filters, ?User $user = null): Builder
    {
        $search = $filters['search'] ?? '';
        $direction = $filters['direction'] ?? 'desc';

        return self::visibleTo(StockMovement::query(), $user)
            ->with('part:id,code,name,unit,deleted_at')
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('reference', 'ilike', "%{$search}%")
                ->orWhere('user_name', 'ilike', "%{$search}%")
                ->orWhereHas('part', fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            ->when($filters['part_id'] ?? null, fn (Builder $q, $id) => $q->where('part_id', $id))
            ->orderBy($filters['sort'] ?? 'created_at', $direction)
            ->orderBy('id', $direction);
    }

    /**
     * The ledger rows the user may see: all of them, or with scope own only the ones they
     * entered (stock has no branch; customer accounts see none).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query;
        }

        return DataScope::constrain($query, $user, 'stock-movements.view', branch: null, customer: null,
            own: fn ($q) => $q->where('user_id', $user->id));
    }
}
