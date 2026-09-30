<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisitItem;

/**
 * Latest PM results of an asset as plain arrays, for the Asset module.
 */
class PmHistoryForAsset
{
    /**
     * @return list<array{visit_ulid: string, visit_no: string, visit_status: string, due_on: string, result: string, checked_at: string|null}>
     */
    public function handle(int $assetId, int $limit = 10): array
    {
        return PmVisitItem::query()
            ->with('visit:id,ulid,visit_no,status,due_on')
            ->whereHas('visit')
            ->where('asset_id', $assetId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PmVisitItem $item) => [
                'visit_ulid' => $item->visit->ulid,
                'visit_no' => $item->visit->visit_no,
                'visit_status' => $item->visit->status,
                'due_on' => $item->visit->due_on->toDateString(),
                'result' => $item->result,
                'checked_at' => $item->checked_at?->toIso8601String(),
            ])
            ->all();
    }
}
