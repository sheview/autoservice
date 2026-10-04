<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\Ticket;

/**
 * Latest tickets about an IP address as plain arrays, for the Asset module (IP management).
 */
class TicketsForIp
{
    /**
     * @return list<array{ulid: string, ticket_no: string, title: string, status: string, created_at: string}>
     */
    public function handle(int $ipAddressId, int $limit = 20): array
    {
        return Ticket::query()
            ->where('ip_address_id', $ipAddressId)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Ticket $ticket) => [
                ...$ticket->only(['ulid', 'ticket_no', 'title', 'status']),
                'created_at' => $ticket->created_at->toIso8601String(),
            ])
            ->all();
    }
}
