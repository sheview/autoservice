<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use App\Modules\Identity\Support\PermissionCatalog;
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

    /** Only where the asset is (branch, location): assets.move within its scope. */
    public function move(User $user, Model $asset): bool
    {
        return $this->permits($user, 'move') && $this->inScope($user, $asset, 'move');
    }

    /** Deleting many at once from the list: the company's admin only (each still needs delete). */
    public function bulkDelete(User $user): bool
    {
        return $user->hasRole(PermissionCatalog::ADMIN_ROLE) && $this->permits($user, 'delete');
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
