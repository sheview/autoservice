<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseWorkflow;

/**
 * Purchase requests. Anyone with purchase.request asks and sees their own; approvers
 * (asset.approve) and buyers (stock.receive) see every request of the company.
 * Customer accounts never do.
 */
class PurchaseRequestPolicy
{
    public static function seesAll(User $user): bool
    {
        return $user->customer_id === null && ($user->can('asset.approve') || $user->can('stock.receive'));
    }

    public function viewAny(User $user): bool
    {
        return $user->customer_id === null && ($user->can('purchase.request') || self::seesAll($user));
    }

    public function view(User $user, PurchaseRequest $request): bool
    {
        return self::seesAll($user) || ($this->viewAny($user) && $request->requested_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->customer_id === null && $user->can('purchase.request');
    }

    /** Changed by whoever asked, while it waits for a decision. */
    public function update(User $user, PurchaseRequest $request): bool
    {
        return $request->status === PurchaseRequest::STATUS_PENDING && $request->requested_by === $user->id && $user->can('purchase.request');
    }

    public function move(User $user, PurchaseRequest $request, string $action): bool
    {
        if (! $this->view($user, $request) || ! PurchaseWorkflow::allows($request, $action)) {
            return false;
        }
        if ($action === 'cancel') {
            return $user->can('asset.approve') || ($request->status === PurchaseRequest::STATUS_PENDING && $request->requested_by === $user->id);
        }

        return $user->can(PurchaseWorkflow::ACTIONS[$action]['permission']);
    }
}
