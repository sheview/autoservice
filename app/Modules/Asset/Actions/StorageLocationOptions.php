<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\StorageLocation;

/**
 * Storage locations of the current tenant for a drop-down (assets and parts): the active ones,
 * plus the one already picked even when it is no longer active.
 */
class StorageLocationOptions
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function handle(?int $current = null): array
    {
        return StorageLocation::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($current, fn ($q) => $q->orWhere('id', $current)))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (StorageLocation $location) => $location->only(['id', 'name']))->all();
    }

    /**
     * Names by id, deleted ones included (to show on old records); null = every location (Excel).
     *
     * @param  list<int>|null  $ids
     * @return array<int, string>
     */
    public function names(?array $ids = null): array
    {
        return $ids === [] ? [] : StorageLocation::withTrashed()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->pluck('name', 'id')->all();
    }

    /**
     * The storage location an asset's "location" text names (picked from the list or typed the
     * same, ignoring case and spaces at the ends); null for any other text.
     */
    public function idFor(?string $location): ?int
    {
        $text = mb_strtolower(trim((string) $location));

        return $text === '' ? null : ($this->idsByName()[$text] ?? null);
    }

    /**
     * The active locations by lower-case name, for reading a name typed in an Excel file.
     *
     * @return array<string, int> lower(name) => id
     */
    public function idsByName(): array
    {
        return StorageLocation::query()->where('is_active', true)->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [mb_strtolower(trim($name)) => $id])->all();
    }
}
