<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnitEvent;

/**
 * Pieces of parts followed by serial number that were put into a device (Asset module) or came
 * back out of it, newest first: what, which serial, when, on which job, by whom.
 */
class AssetPartHistory
{
    /**
     * @return list<array{kind: string, part: string, serial: string, ticket_id: int|null, by: string|null, at: string}>
     */
    public function handle(int $assetId): array
    {
        return PartUnitEvent::query()
            ->with('unit.part:id,code,name')
            ->where('asset_id', $assetId)
            ->whereIn('action', [PartUnitEvent::ACTION_ISSUE, PartUnitEvent::ACTION_RETURN])
            ->latest('created_at')->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (PartUnitEvent $event) => [
                'kind' => $event->action === PartUnitEvent::ACTION_ISSUE ? 'installed' : 'returned',
                'part' => $event->unit?->part?->name ?? '-',
                'serial' => $event->serial_number,
                'ticket_id' => $event->ticket_id,
                'by' => $event->user_name,
                'at' => $event->created_at->toIso8601String(),
            ])
            ->all();
    }
}
