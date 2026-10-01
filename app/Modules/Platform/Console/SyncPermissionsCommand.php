<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Actions\SeedDefaultRoles;
use App\Modules\Identity\Actions\SyncPermissions;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Run after every deploy: adds the permissions of PermissionCatalog that are new, resets the
 * platform's own roles to the catalog, and gives each company's admin role every permission.
 * Other company roles are left alone: each company may have changed them; with --defaults they
 * also get the permissions that are new in their default role (nothing is ever taken away).
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'platform:sync-permissions {--defaults : Also add new default permissions to the other company roles}';

    protected $description = 'Bring permissions and roles up to date with the permission catalog';

    public function handle(SyncPermissions $syncPermissions, SeedDefaultRoles $seedDefaultRoles, TenantContext $context): int
    {
        $syncPermissions->handle();

        $platform = Tenant::query()->where('is_platform', true)->first();
        if ($platform) {
            $seedDefaultRoles->handle($platform);
        }

        $count = 0;
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, &$count) {
            $context->run($tenant, function () {
                foreach (PermissionCatalog::DEFAULT_ROLES as $name => $role) {
                    $existing = Role::query()->where('name', $name)->first();
                    if ($existing === null) {
                        continue;
                    }
                    if ($role['permissions'] === '*') {
                        $existing->syncPermissions(PermissionCatalog::tenantPermissions());
                    } elseif ($this->option('defaults')) {
                        $existing->givePermissionTo($role['permissions']);
                    }
                }
            });
            $count++;
        });

        $this->call('permission:cache-reset');
        $this->info("Permissions are up to date (platform + {$count} companies).");

        return self::SUCCESS;
    }
}
