<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The PM checklist list query: search + category filter + sort.
 */
class SearchChecklists
{
    public const SORTABLE = ['name', 'updated_at'];

    /**
     * @return array{search: string, category: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $category = (string) $request->input('category', '');

        return [
            'search' => $request->string('search')->trim()->value(),
            // a category id, or "general" = the checklist without a category
            'category' => $category === 'general' || ctype_digit($category) ? $category : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<PmChecklist>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $category = (string) ($filters['category'] ?? '');

        return PmChecklist::query()
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%"))
            ->when($category === 'general', fn (Builder $q) => $q->whereNull('asset_category_id'))
            ->when(ctype_digit($category), fn (Builder $q) => $q->where('asset_category_id', (int) $category))
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }
}
