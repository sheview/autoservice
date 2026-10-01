<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Validation\ValidationException;

/**
 * Moves a purchase request along PurchaseWorkflow and records who did it and when; the note is
 * the reason of a rejection, the order details (supplier, PO) or what was received.
 */
class MovePurchaseRequest
{
    public function handle(PurchaseRequest $request, string $action, User $actor, ?string $note = null): PurchaseRequest
    {
        if (! PurchaseWorkflow::allows($request, $action)) {
            throw ValidationException::withMessages(['action' => __('inventory.purchase_requests.not_allowed')]);
        }
        if (in_array($action, PurchaseWorkflow::NEEDS_NOTE, true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('inventory.purchase_requests.reason_required')]);
        }
        $note = filled($note) ? $note : null;

        $request->update([
            'status' => PurchaseWorkflow::ACTIONS[$action]['to'],
            ...match ($action) {
                'approve', 'reject' => ['decided_by' => $actor->id, 'decided_by_name' => $actor->name, 'decided_at' => now(), 'decision_note' => $note],
                'order' => ['ordered_by_name' => $actor->name, 'ordered_at' => now(), 'order_note' => $note],
                'receive' => ['received_by_name' => $actor->name, 'received_at' => now(), 'receive_note' => $note],
                'cancel' => ['decision_note' => $note ?? $request->decision_note],
            },
        ]);

        activity()->performedOn($request)->causedBy($actor)->event('purchase_'.$action)
            ->withProperties(['pr_no' => $request->pr_no, 'note' => $note])
            ->log(__('inventory.purchase_requests.log.'.$action));

        return $request;
    }
}
