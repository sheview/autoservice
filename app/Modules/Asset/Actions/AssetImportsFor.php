<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetImport;

/**
 * The asset imports another module asked for (by a key of their context, e.g. pm_plan_id), newest
 * first, as that module shows them: status, counts and the rows that failed.
 */
class AssetImportsFor
{
    /**
     * @return list<array{id: int, file_name: string, status: string, total_rows: int, created_rows: int, updated_rows: int, failed_rows: int,
     *     errors: list<array{row: int|null, messages: list<string>}>, created_at: string, finished_at: string|null}>
     */
    public function handle(string $contextKey, int $value, int $limit = 10): array
    {
        return AssetImport::query()
            ->where("context->{$contextKey}", $value)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AssetImport $import) => [
                ...$import->only(['id', 'file_name', 'status', 'total_rows', 'created_rows', 'updated_rows', 'failed_rows', 'errors']),
                'created_at' => $import->created_at->toIso8601String(),
                'finished_at' => $import->finished_at?->toIso8601String(),
            ])
            ->all();
    }
}
