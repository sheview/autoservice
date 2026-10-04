<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Laravel\Pennant\Feature;

/**
 * Which business modules a tenant has switched on (config/modules.php "toggleable"),
 * and the sidebar built from config/modules.php "navigation".
 *
 * Each module is the Pennant feature "module.{key}" scoped to the Tenant; the features are
 * defined in PlatformServiceProvider.
 */
class Modules
{
    public function __construct(private TenantContext $context) {}

    public static function feature(string $key): string
    {
        return "module.{$key}";
    }

    /**
     * @return list<string>
     */
    public function toggleable(): array
    {
        return array_keys(config('modules.toggleable', []));
    }

    public function isToggleable(string $key): bool
    {
        return array_key_exists($key, config('modules.toggleable', []));
    }

    /**
     * Whether the module is on for $tenant (default: the current tenant). No tenant = off.
     */
    public function enabled(string $key, ?Tenant $tenant = null): bool
    {
        $tenant ??= $this->context->tenant();

        if ($tenant === null || $tenant->is_platform || ! $this->isToggleable($key)) {
            return false;
        }

        return Feature::for($tenant)->active(self::feature($key));
    }

    /**
     * @return array<string, bool> module key => on/off
     */
    public function states(Tenant $tenant): array
    {
        $states = [];
        foreach ($this->toggleable() as $key) {
            $states[$key] = $this->enabled($key, $tenant);
        }

        return $states;
    }

    /**
     * Sidebar items the user may see in the current tenant.
     *
     * @param  iterable<string>  $permissions  the permission names the UI already received
     * @param  bool  $customerAccount  hide items marked "staff" (pages a customer account may not open)
     * @return list<array{title: string, href: string, icon: string, group: string|null}>
     */
    public function navigation(iterable $permissions, bool $customerAccount = false): array
    {
        $permissions = collect($permissions)->all();
        $items = [];

        foreach (config('modules.navigation', []) as $item) {
            // "permission": one name, or several of which any will do.
            if (isset($item['permission']) && array_intersect((array) $item['permission'], $permissions) === []) {
                continue;
            }
            if ($customerAccount && ($item['staff'] ?? false)) {
                continue;
            }
            // "module": one key, or several that must all be on.
            if (isset($item['module']) && collect((array) $item['module'])->contains(fn (string $key) => ! $this->enabled($key))) {
                continue;
            }
            // Pages about the company itself: not in the platform tenant (it is no company).
            if (($item['company'] ?? false) && (app(TenantContext::class)->tenant()?->is_platform ?? true)) {
                continue;
            }

            $items[] = [
                'title' => __("ui.{$item['title']}"),
                'href' => route($item['route'], absolute: false),
                'icon' => $item['icon'],
                'group' => isset($item['group']) ? __("ui.nav_groups.{$item['group']}") : null,
            ];
        }

        return $items;
    }
}
