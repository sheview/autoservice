<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestEvent;

/**
 * Writes down a step of a purchase request (purchase_request_events): what was done, the status
 * before and after, who did it and when. The request's history on its page is read from these.
 */
class LogPurchaseEvent
{
    public function handle(PurchaseRequest $request, string $action, ?string $fromStatus, ?User $actor, ?string $note = null): PurchaseRequestEvent
    {
        return PurchaseRequestEvent::create([
            'purchase_request_id' => $request->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $request->status,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'note' => filled($note) ? $note : null,
            'created_at' => now(),
        ]);
    }
}
