<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\PurchaseRequest as PR;

/**
 * The moves of a purchase request, from which statuses each is allowed, and who may make it:
 *   approve, reject  purchase-requests.approve; a rejection says why
 *   order            purchase-requests.receive (the buyers); optional, a delivery may come first
 *   cancel           whoever asked (while waiting), an approver (until ordered), or a buyer
 *                    (ordered, nothing delivered yet)
 *
 * After the order the status follows the quantities (progress()): deliveries are recorded as
 * receipts (ReceivePurchase), registered into the system (RegisterPurchaseReceipt) and handed out
 * on issue/loan requests (RecordPurchaseIssued).
 */
class PurchaseWorkflow
{
    public const ACTIONS = [
        'approve' => ['from' => [PR::STATUS_PENDING], 'to' => PR::STATUS_APPROVED, 'permission' => 'purchase-requests.approve'],
        'reject' => ['from' => [PR::STATUS_PENDING], 'to' => PR::STATUS_REJECTED, 'permission' => 'purchase-requests.approve'],
        'order' => ['from' => [PR::STATUS_APPROVED], 'to' => PR::STATUS_ORDERED, 'permission' => 'purchase-requests.receive'],
        'cancel' => ['from' => [PR::STATUS_PENDING, PR::STATUS_APPROVED, PR::STATUS_ORDERED], 'to' => PR::STATUS_CANCELLED, 'permission' => null],
    ];

    /** Moves that need a note (a reason). */
    public const NEEDS_NOTE = ['reject'];

    /**
     * Deliveries are taken once approved (recording the order is optional) and until all of it
     * has come.
     */
    public const RECEIVABLE = [PR::STATUS_APPROVED, PR::STATUS_ORDERED, PR::STATUS_PARTIALLY_RECEIVED];

    public static function allows(PR $request, string $action): bool
    {
        return isset(self::ACTIONS[$action]) && in_array($request->status, self::ACTIONS[$action]['from'], true)
            // Once something has arrived it is no longer cancelled; what came is registered instead.
            && ! ($action === 'cancel' && $request->qty_received > 0);
    }

    /**
     * A delivery can be recorded: ordered and not all of it here yet. Checked apart from the
     * policy, which a superadmin passes whatever the state (Gate::before).
     */
    public static function receivable(PR $request): bool
    {
        return in_array($request->status, self::RECEIVABLE, true) && $request->qty_received < $request->quantity;
    }

    /** Something has arrived that is not registered yet. */
    public static function registrable(PR $request): bool
    {
        return in_array($request->status, PR::RECEIVING_STATUSES, true) && $request->qty_registered < $request->qty_received;
    }

    /**
     * The status an ordered request has reached by its quantities: partly or all delivered, all
     * registered, all handed out. Statuses before the order (and the closed ones) stay as they are.
     */
    public static function progress(PR $request): string
    {
        if (! in_array($request->status, [PR::STATUS_APPROVED, PR::STATUS_ORDERED, ...PR::RECEIVING_STATUSES], true)) {
            return $request->status;
        }

        $quantity = $request->quantity;

        return match (true) {
            $request->qty_issued >= $quantity => PR::STATUS_ISSUED,
            $request->qty_registered >= $quantity => PR::STATUS_REGISTERED,
            $request->qty_received >= $quantity => PR::STATUS_RECEIVED,
            $request->qty_received > 0 => PR::STATUS_PARTIALLY_RECEIVED,
            default => $request->status === PR::STATUS_APPROVED ? PR::STATUS_APPROVED : PR::STATUS_ORDERED,
        };
    }
}
