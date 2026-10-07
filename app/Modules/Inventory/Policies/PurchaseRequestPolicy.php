<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * purchase-requests.* permissions. Requests have no branch or customer: scope own = the ones the
 * user asked for, any wider scope = every request of the company. Listed with view, asked with
 * create, changed by the requester while pending with update, approved/rejected with approve; ordered,
 * received and registered with receive (the buyers); cancelled by whoever asked (while pending), an
 * approver (until ordered) or a buyer (ordered, nothing delivered). Customer accounts never see them.
 */
class PurchaseRequestPolicy extends TenantPolicy
{
    protected string $resource = 'purchase-requests';

    protected ?string $projectColumn = 'contract_id';

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;

    /** Whether the list shows everyone's requests (otherwise only the user's own). */
    public static function seesEveryone(User $user): bool
    {
        return $user->customer_id === null
            && in_array(DataScope::of($user, 'purchase-requests.view'), [PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH], true);
    }

    /** Seen within the reach of view, or by an approver / buyer who can act on it (links in alerts). */
    public function view(User $user, Model $model): bool
    {
        return parent::view($user, $model) || ($model instanceof PurchaseRequest && $this->handles($user, $model));
    }

    /** Changed only by whoever asked (with purchase-requests.update), while it waits for a decision. */
    public function update(User $user, Model $model): bool
    {
        return $model->getAttribute('status') === PurchaseRequest::STATUS_PENDING
            && (int) $model->getAttribute('requested_by') === $user->id
            && parent::update($user, $model);
    }

    /** Requests are never deleted (they are cancelled). */
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function move(User $user, PurchaseRequest $request, string $action): bool
    {
        if (! $this->view($user, $request) || ! PurchaseWorkflow::allows($request, $action)) {
            return false;
        }
        if ($action === 'cancel') {
            return ($request->status !== PurchaseRequest::STATUS_ORDERED && $this->approves($user, $request))
                || ($request->status === PurchaseRequest::STATUS_ORDERED && $this->buys($user, $request))
                || ($request->status === PurchaseRequest::STATUS_PENDING && $request->requested_by === $user->id);
        }

        $permission = PurchaseWorkflow::ACTIONS[$action]['permission'];

        return $user->checkPermissionTo($permission) && $this->inScope($user, $request, $this->abilityOf($permission));
    }

    /** A delivery may be recorded: ordered and not all of it here yet, by a buyer. */
    public function receive(User $user, PurchaseRequest $request): bool
    {
        return PurchaseWorkflow::receivable($request) && $this->buys($user, $request);
    }

    /** What arrived may be registered (as assets or parts), by a buyer, while some of it is not yet. */
    public function register(User $user, PurchaseRequest $request): bool
    {
        return PurchaseWorkflow::registrable($request) && $this->buys($user, $request);
    }

    /** A buyer (purchase-requests.receive) who reaches this request. */
    public function buys(User $user, PurchaseRequest $request): bool
    {
        return $this->permits($user, 'receive') && $this->inScope($user, $request, 'receive');
    }

    /** An approver who reaches this request. */
    public function approves(User $user, PurchaseRequest $request): bool
    {
        return $this->permits($user, 'approve') && $this->inScope($user, $request, 'approve');
    }

    /** An approver or buyer who reaches this request (they may add quotations while it is open). */
    public function handles(User $user, PurchaseRequest $request): bool
    {
        return $this->approves($user, $request) || $this->buys($user, $request);
    }

    protected function permits(User $user, string $ability): bool
    {
        return $user->customer_id === null && parent::permits($user, $ability);
    }

    protected function owns(User $user, Model $model): bool
    {
        return (int) $model->getAttribute('requested_by') === $user->id;
    }

    private function abilityOf(string $permission): string
    {
        return substr($permission, strlen('purchase-requests.'));
    }
}
