<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use Laravel\Pennant\Feature;

/**
 * Switches business modules on or off for a customer tenant and logs the change.
 * Data of a module that is switched off stays in the database; only its pages disappear.
 */
class SetTenantModules
{
    public function __construct(private Modules $modules) {}

    /**
     * @param  array<string, bool>  $states  module key => on/off (keys that are not toggleable are ignored)
     */
    public function handle(Tenant $tenant, array $states): void
    {
        $before = $this->modules->states($tenant);

        foreach ($states as $key => $on) {
            if (! $this->modules->isToggleable($key)) {
                continue;
            }

            $on
                ? Feature::for($tenant)->activate(Modules::feature($key))
                : Feature::for($tenant)->deactivate(Modules::feature($key));
        }

        $after = $this->modules->states($tenant);

        // Logged in the platform tenant (the current one), where the change was made.
        if ($before !== $after) {
            activity('platform')
                ->performedOn($tenant)
                ->event('modules_updated')
                ->withProperties(['old' => $before, 'attributes' => $after])
                ->log('เปลี่ยนโมดูลที่เปิดใช้ของบริษัท');
        }
    }
}
