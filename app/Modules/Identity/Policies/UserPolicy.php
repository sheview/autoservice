<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Users of the company: users.view to see them, users.manage to add and change them. Scope
 * branch = users of the user's branch (or of none); own = only themself.
 */
class UserPolicy extends TenantPolicy
{
    protected string $resource = 'users';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $customerColumn = null;

    protected function owns(User $user, Model $model): bool
    {
        return (int) $model->getKey() === (int) $user->id;
    }
}
