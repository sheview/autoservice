<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The part list query: search + filters (status, stock level) + sort.
 */
class SearchParts
{
    public const SORTABLE = ['code', 'name', 'qty_on_hand', 'unit_cost', 'created_at'];

    public const STATUSES = ['active', 'inactive'];

    /** low = at or below the reorder point (but not empty), out = nothing left. */
    public const STOCK_LEVELS = ['low', 'out'];

    /**
     * @return array{search: string, status: string|null, stock: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'stock' => in_array($request->input('stock'), self::STOCK_LEVELS, true) ? $request->input('stock') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'code',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Part>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return Part::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('part_number', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")
                // Or a piece with that serial number.
                ->orWhereIn('id', app(PartIdsWithSerial::class)->handle($search))))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('is_active', $status === 'active'))
            ->when($filters['stock'] ?? null, fn (Builder $q, $stock) => match ($stock) {
                'out' => $q->where('qty_on_hand', 0),
                'low' => $q->where('min_qty', '>', 0)->where('qty_on_hand', '>', 0)->whereColumn('qty_on_hand', '<=', 'min_qty'),
            })
            ->orderBy($filters['sort'] ?? 'code', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }
}
