<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A page of the asset list (SearchAssets: the user's scope + filters + sort) as plain rows,
 * for other modules that list assets (e.g. choosing assets to print labels for).
 */
class PaginateAssets
{
    public function __construct(private SearchAssets $search) {}

    /**
     * @param  array<string, mixed>  $filters  from SearchAssets::filtersFrom()
     * @param  list<int>|null  $onlyIds  keep only these assets (null = no limit)
     * @param  list<int>  $exceptIds  leave these assets out
     * @return LengthAwarePaginator<array{id: int, ulid: string, asset_code: string, name: string, category: string|null,
     *     branch: string|null, customer_id: int|null, serial_number: string|null, location: string|null, status: string}>
     */
    public function handle(User $user, array $filters, ?array $onlyIds = null, array $exceptIds = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->search->handle($user, $filters)
            ->with(['category:id,name', 'branch:id,name'])
            ->when($onlyIds !== null, fn ($q) => $q->whereKey($onlyIds))
            ->when($exceptIds !== [], fn ($q) => $q->whereKeyNot($exceptIds))
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Asset $asset) => [
                ...$asset->only(['id', 'ulid', 'asset_code', 'name', 'customer_id', 'serial_number', 'location', 'status']),
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
            ]);
    }
}
