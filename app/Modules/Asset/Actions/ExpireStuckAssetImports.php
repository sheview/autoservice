<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetImport;

/**
 * Gives up on imports of the current tenant that will never finish, so none waits on the page forever:
 * one that never started (the request that should have run it died, or it was queued with no worker)
 * and one that stopped half way (the progress counts are saved every few rows, so a running import
 * is never quiet for long). The uploader is told to upload the file again.
 */
class ExpireStuckAssetImports
{
    public const START_WITHIN_MINUTES = 5;

    public const QUIET_MINUTES = 15;

    public function handle(): void
    {
        $this->expire(AssetImport::STATUS_PENDING, 'created_at', self::START_WITHIN_MINUTES, 'asset.imports.never_started');
        $this->expire(AssetImport::STATUS_PROCESSING, 'updated_at', self::QUIET_MINUTES, 'asset.imports.stopped');
    }

    private function expire(string $status, string $since, int $minutes, string $message): void
    {
        AssetImport::query()->where('status', $status)->where($since, '<', now()->subMinutes($minutes))
            ->each(fn (AssetImport $import) => $import->update([
                'status' => AssetImport::STATUS_FAILED,
                'errors' => [...($import->errors ?? []), ['row' => null, 'messages' => [__($message)]]],
                'finished_at' => now(),
            ]));
    }
}
