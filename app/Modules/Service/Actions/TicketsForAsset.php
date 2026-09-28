<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * Latest tickets of an asset as plain arrays, for the Asset module.
 */
class TicketsForAsset
{
    /**
     * @return list<array{ulid: string, ticket_no: string, title: string, status: string, priority: string, created_at: string}>
     */
    public function handle(int $assetId, int $limit = 10): array
    {
        return Ticket::query()
            ->where('asset_id', $assetId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket) => [
                ...$ticket->only(['ulid', 'ticket_no', 'title', 'status', 'priority']),
                'created_at' => $ticket->created_at->toIso8601String(),
            ])
            ->all();
    }
}
