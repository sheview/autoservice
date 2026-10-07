<?php

namespace App\Modules\RoomAccess\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * room-access.* for requests. "own" = requests the user made. Customer accounts have none of it.
 *
 *   view           room-access.view in scope
 *   create         room-access.request
 *   update/submit  the requester, while it is a draft
 *   cancel         the requester, before anyone is inside
 */
class RoomAccessRequestPolicy extends TenantPolicy
{
    protected string $resource = 'room-access';

    protected ?string $projectColumn = 'contract_id';

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;

    protected array $actions = ['create' => 'request', 'update' => 'request'];

    protected function permits(User $user, string $ability): bool
    {
        return $user->customer_id === null && parent::permits($user, $ability);
    }

    protected function owns(User $user, Model $model): bool
    {
        return (int) $model->getAttribute('requester_id') === $user->id;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'update') && $this->inScope($user, $model, 'update')
            && (int) $model->getAttribute('requester_id') === $user->id && $model->getAttribute('status') === RoomAccessRequest::STATUS_DRAFT;
    }

    public function cancel(User $user, RoomAccessRequest $request): bool
    {
        return (int) $request->requester_id === $user->id
            && in_array($request->status, [RoomAccessRequest::STATUS_DRAFT, RoomAccessRequest::STATUS_PENDING, RoomAccessRequest::STATUS_APPROVED], true);
    }
}
