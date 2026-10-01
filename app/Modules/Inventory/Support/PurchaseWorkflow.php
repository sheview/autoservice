<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\PurchaseRequest as PR;

/**
 * The moves of a purchase request, from which statuses each is allowed, and who may make it:
 *   approve, reject  purchase-requests.approve; a rejection says why
 *   order, receive   purchase-requests.receive (whoever buys and takes deliveries)
 *   cancel           whoever asked (while waiting) or an approver (until ordered)
 */
class PurchaseWorkflow
{
    public const ACTIONS = [
        'approve' => ['from' => [PR::STATUS_PENDING], 'to' => PR::STATUS_APPROVED, 'permission' => 'purchase-requests.approve'],
        'reject' => ['from' => [PR::STATUS_PENDING], 'to' => PR::STATUS_REJECTED, 'permission' => 'purchase-requests.approve'],
        'order' => ['from' => [PR::STATUS_APPROVED], 'to' => PR::STATUS_ORDERED, 'permission' => 'purchase-requests.receive'],
        'receive' => ['from' => [PR::STATUS_ORDERED], 'to' => PR::STATUS_RECEIVED, 'permission' => 'purchase-requests.receive'],
        'cancel' => ['from' => [PR::STATUS_PENDING, PR::STATUS_APPROVED], 'to' => PR::STATUS_CANCELLED, 'permission' => null],
    ];

    /** Moves that need a note (a reason). */
    public const NEEDS_NOTE = ['reject'];

    public static function allows(PR $request, string $action): bool
    {
        return isset(self::ACTIONS[$action]) && in_array($request->status, self::ACTIONS[$action]['from'], true);
    }
}
