<?php

/*
|--------------------------------------------------------------------------
| Modules and navigation
|--------------------------------------------------------------------------
|
| "toggleable": business modules that the platform can switch on or off per tenant.
| Each one is a Pennant feature "module.{key}" scoped to the Tenant; "default" is the value
| a tenant gets until the platform changes it. Routes of a module use the middleware
| "module:{key}" (404 when switched off). Core modules (Tenancy, Identity, Platform) are
| always on and are not listed here. The platform tenant never gets business modules.
|
| "navigation": the sidebar, in order. An item is shown when the user has "permission" (if set)
| and the tenant has "module" switched on (if set). "icon" is a lucide icon name that
| resources/js/components/AppSidebar.vue knows. "title" is a key of lang/{locale}/ui.php.
|
*/

return [
    'toggleable' => [
        'asset' => ['default' => true],
        'contract' => ['default' => true],
        'service' => ['default' => true],
    ],

    'navigation' => [
        ['title' => 'nav.dashboard', 'route' => 'dashboard', 'icon' => 'layout-grid'],
        ['title' => 'nav.tickets', 'route' => 'service.tickets.index', 'icon' => 'wrench', 'permission' => 'ticket.view', 'module' => 'service'],
        ['title' => 'nav.assets', 'route' => 'asset.assets.index', 'icon' => 'hard-drive', 'permission' => 'asset.view', 'module' => 'asset'],
        ['title' => 'nav.asset_categories', 'route' => 'asset.categories.index', 'icon' => 'tags', 'permission' => 'asset_category.view', 'module' => 'asset'],
        ['title' => 'nav.contracts', 'route' => 'contract.contracts.index', 'icon' => 'file-text', 'permission' => 'contract.view', 'module' => 'contract'],
        ['title' => 'nav.customers', 'route' => 'contract.customers.index', 'icon' => 'briefcase', 'permission' => 'customer.view', 'module' => 'contract'],
        ['title' => 'nav.holidays', 'route' => 'service.holidays.index', 'icon' => 'calendar-days', 'permission' => 'holiday.view', 'module' => 'service'],
        ['title' => 'nav.users', 'route' => 'identity.users.index', 'icon' => 'users', 'permission' => 'user.view'],
        ['title' => 'nav.roles', 'route' => 'identity.roles.index', 'icon' => 'shield-check', 'permission' => 'role.view'],
        ['title' => 'nav.tenants', 'route' => 'platform.impersonation.index', 'icon' => 'building-2', 'permission' => 'platform.impersonate'],
    ],
];
