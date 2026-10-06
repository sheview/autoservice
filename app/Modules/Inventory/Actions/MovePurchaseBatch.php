<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or turns down, in one go, the requests of a batch the approver may decide now (each
 * through MovePurchaseRequest, so each keeps its own history and alert). The caller passes only
 * the requests the user may move; those not waiting for a decision are left as they are.
 */
class MovePurchaseBatch
{
    public const ACTIONS = ['approve', 'reject'];

    public function __construct(private MovePurchaseRequest $move) {}

    /**
     * @param  iterable<PurchaseRequest>  $requests
     * @return int how many were moved
     */
    public function handle(iterable $requests, string $action, User $actor, ?string $note = null): int
    {
        if (in_array($action, PurchaseWorkflow::NEEDS_NOTE, true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('inventory.purchase_requests.reason_required')]);
        }

        return DB::transaction(function () use ($requests, $action, $actor, $note) {
            $moved = 0;
            foreach ($requests as $request) {
                if (PurchaseWorkflow::allows($request, $action)) {
                    $this->move->handle($request, $action, $actor, $note);
                    $moved++;
                }
            }
            if ($moved === 0) {
                throw ValidationException::withMessages(['action' => __('inventory.purchase_requests.batch_nothing')]);
            }

            return $moved;
        });
    }
}
