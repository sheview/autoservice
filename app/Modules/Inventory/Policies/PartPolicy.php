<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * parts.* permissions. Parts have no branch or customer: one stock for the whole company, so
 * any scope but customer reaches every part. Stock changes need stock-movements.create
 * (StockMovementRequest); issue/loan forms parts.issue (PartCheckoutController).
 */
class PartPolicy extends TenantPolicy
{
    protected string $resource = 'parts';

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;

    /** Customer accounts never see the company's stock. */
    protected function permits(User $user, string $ability): bool
    {
        return $user->customer_id === null && parent::permits($user, $ability);
    }

    /** Scope own of parts.* still reaches every part ("own" applies to the forms and movements). */
    protected function owns(User $user, Model $model): bool
    {
        return true;
    }
}
