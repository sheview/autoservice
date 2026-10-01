<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Assets (assets.*). "Own" = the assets the user holds now: asked for or handed over to them
 * on an issue/loan form (SearchAssets::heldBy).
 */
class AssetPolicy extends TenantPolicy
{
    protected string $resource = 'assets';

    public function import(User $user): bool
    {
        return $this->permits($user, 'import');
    }

    public function export(User $user): bool
    {
        return $this->permits($user, 'export');
    }

    protected function owns(User $user, Model $model): bool
    {
        return SearchAssets::holds($user, $model->getKey());
    }
}
