<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Models\Manual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The manuals list: search (title, description, category), a category filter, sort.
 */
class SearchManuals
{
    public const SORTABLE = ['title', 'category', 'updated_at'];

    /**
     * @return array{search: string, category: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'category' => $request->filled('category') ? $request->string('category')->trim()->value() : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'updated_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Manual>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';

        return Manual::query()
            ->withCount(['media as files_count' => fn ($q) => $q->where('collection_name', (new Manual)->attachmentCollection())])
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn (Builder $q, $category) => $q->where('category', $category))
            ->orderBy($filters['sort'] ?? 'updated_at', $filters['direction'] ?? 'desc')
            ->orderBy('id');
    }
}
