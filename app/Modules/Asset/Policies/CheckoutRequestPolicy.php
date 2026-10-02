<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue/loan requests (asset-checkouts.*), each within its scope (DataScope): all / branch
 * (branch_id) / own (asked for by the user, or made out to them). Customer accounts have none.
 *
 *   view            asset-checkouts.view
 *   create          asset-checkouts.request (for oneself) or .create (for anyone)
 *   edit / submit   the requester, while it is a draft
 *   approve, reject asset-checkouts.approve
 *   fulfill         asset-checkouts.fulfill: hand out, backorder, give up what is left, close
 *   return          asset-checkouts.return
 *   cancel          the requester, or an approver, before the decision
 */
class CheckoutRequestPolicy extends TenantPolicy
{
    protected string $resource = 'asset-checkouts';

    protected ?string $customerColumn = null;

    public function view(User $user, Model $request): bool
    {
        return ($this->permits($user, 'view') && $this->inScope($user, $request, 'view'))
            || $this->approve($user, $request) || $this->fulfill($user, $request) || $this->returnItems($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->customer_id === null && ($this->permits($user, 'request') || $this->permits($user, 'create'));
    }

    /** May write a request for someone else (otherwise only for oneself). */
    public function createForOthers(User $user): bool
    {
        return $user->customer_id === null && $this->permits($user, 'create');
    }

    public function update(User $user, Model $request): bool
    {
        return $request->status === CheckoutRequest::STATUS_DRAFT && (int) $request->requester_id === (int) $user->id && $this->create($user);
    }

    public function approve(User $user, Model $request): bool
    {
        return $this->permits($user, 'approve') && $this->inScope($user, $request, 'approve');
    }

    public function fulfill(User $user, Model $request): bool
    {
        return $this->permits($user, 'fulfill') && $this->inScope($user, $request, 'fulfill');
    }

    public function returnItems(User $user, Model $request): bool
    {
        return $this->permits($user, 'return') && $this->inScope($user, $request, 'return');
    }

    public function cancel(User $user, Model $request): bool
    {
        return in_array($request->status, [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_PENDING], true)
            && ((int) $request->requester_id === (int) $user->id || $this->approve($user, $request));
    }

    /** Scope "own": asked for by the user, or made out to them. */
    protected function owns(User $user, Model $request): bool
    {
        return (int) $request->getAttribute('requester_id') === (int) $user->id
            || (int) $request->getAttribute('borrower_user_id') === (int) $user->id;
    }
}
