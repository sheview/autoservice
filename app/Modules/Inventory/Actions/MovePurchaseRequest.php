<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseAlert;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a purchase request along PurchaseWorkflow, records who did it and when (on the request
 * and in its history) and announces it (alert purchase_{approved,rejected,ordered,cancelled}).
 * The note is the reason of a rejection or cancellation, or the order details (supplier, PO).
 */
class MovePurchaseRequest
{
    public const ALERTS = ['approve' => 'purchase_approved', 'reject' => 'purchase_rejected', 'order' => 'purchase_ordered', 'cancel' => 'purchase_cancelled'];

    public function __construct(private LogPurchaseEvent $logEvent) {}

    public function handle(PurchaseRequest $request, string $action, User $actor, ?string $note = null): PurchaseRequest
    {
        if (! PurchaseWorkflow::allows($request, $action)) {
            throw ValidationException::withMessages(['action' => __('inventory.purchase_requests.not_allowed')]);
        }
        if (in_array($action, PurchaseWorkflow::NEEDS_NOTE, true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('inventory.purchase_requests.reason_required')]);
        }
        $note = filled($note) ? $note : null;
        $from = $request->status;

        DB::transaction(function () use ($request, $action, $actor, $note, $from) {
            $request->update([
                'status' => PurchaseWorkflow::ACTIONS[$action]['to'],
                ...match ($action) {
                    'approve', 'reject' => ['decided_by' => $actor->id, 'decided_by_name' => $actor->name, 'decided_at' => now(), 'decision_note' => $note],
                    'order' => ['ordered_by_name' => $actor->name, 'ordered_at' => now(), 'order_note' => $note],
                    'cancel' => ['decision_note' => $note ?? $request->decision_note],
                },
            ]);
            $this->logEvent->handle($request, $action, $from, $actor, $note);
        });

        activity()->performedOn($request)->causedBy($actor)->event('purchase_'.$action)
            ->withProperties(['pr_no' => $request->pr_no, 'note' => $note])
            ->log(__('inventory.purchase_requests.log.'.$action));
        PurchaseAlert::send(self::ALERTS[$action], $request, $actor->name, $note);

        return $request;
    }
}
