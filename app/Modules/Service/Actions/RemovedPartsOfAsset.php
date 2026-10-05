<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\TicketRemovedPart;

/**
 * Pieces technicians took out of a device on its jobs, newest first, for its history (Asset module).
 */
class RemovedPartsOfAsset
{
    /**
     * @return list<array{kind: string, part: string, serial: string|null, problem: string|null, disposition: string, ticket_id: int, by: string|null, at: string}>
     */
    public function handle(int $assetId): array
    {
        return TicketRemovedPart::query()->where('asset_id', $assetId)->latest('id')->limit(100)->get()
            ->map(fn (TicketRemovedPart $piece) => [
                'kind' => 'removed',
                'part' => $piece->item_name,
                'serial' => $piece->serial_number,
                'problem' => $piece->problem,
                'disposition' => $piece->disposition,
                'ticket_id' => $piece->ticket_id,
                'by' => $piece->user_name,
                'at' => $piece->created_at->toIso8601String(),
            ])
            ->all();
    }
}
