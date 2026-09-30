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
| and the tenant has "module" switched on (if set); "staff" items are hidden from customer
| accounts. "icon" is a lucide icon name that
| resources/js/components/AppSidebar.vue knows. "title" is a key of lang/{locale}/ui.php.
|
*/

return [
    'toggleable' => [
        'asset' => ['default' => true],
        'contract' => ['default' => true],
        'service' => ['default' => true],
        'maintenance' => ['default' => true],
        'labeling' => ['default' => true],
        'inventory' => ['default' => true],
        'survey' => ['default' => true],
    ],

    'navigation' => [
        ['title' => 'nav.dashboard', 'route' => 'dashboard', 'icon' => 'layout-grid'],
        ['title' => 'nav.tickets', 'route' => 'service.tickets.index', 'icon' => 'wrench', 'permission' => 'ticket.view', 'module' => 'service'],
        ['title' => 'nav.pm_visits', 'route' => 'maintenance.visits.index', 'icon' => 'calendar-check', 'permission' => 'pm.view', 'module' => 'maintenance'],
        ['title' => 'nav.assets', 'route' => 'asset.assets.index', 'icon' => 'hard-drive', 'permission' => 'asset.view', 'module' => 'asset'],
        ['title' => 'nav.asset_categories', 'route' => 'asset.categories.index', 'icon' => 'tags', 'permission' => 'asset_category.view', 'module' => 'asset'],
        ['title' => 'nav.contracts', 'route' => 'contract.contracts.index', 'icon' => 'file-text', 'permission' => 'contract.view', 'module' => 'contract'],
        ['title' => 'nav.customers', 'route' => 'contract.customers.index', 'icon' => 'briefcase', 'permission' => 'customer.view', 'module' => 'contract'],
        ['title' => 'nav.pm_plans', 'route' => 'maintenance.plans.index', 'icon' => 'clipboard-list', 'permission' => 'pm.view', 'module' => 'maintenance', 'staff' => true],
        ['title' => 'nav.labels', 'route' => 'labeling.labels.index', 'icon' => 'qr-code', 'permission' => 'sticker.print', 'module' => 'labeling', 'staff' => true],
        ['title' => 'nav.parts', 'route' => 'inventory.parts.index', 'icon' => 'package', 'permission' => 'part.view', 'module' => 'inventory', 'staff' => true],
        ['title' => 'nav.stock_movements', 'route' => 'inventory.movements.index', 'icon' => 'arrow-left-right', 'permission' => 'stock.view', 'module' => 'inventory', 'staff' => true],
        ['title' => 'nav.surveys', 'route' => 'survey.surveys.index', 'icon' => 'star', 'permission' => 'survey.view', 'module' => 'survey', 'staff' => true],
        ['title' => 'nav.pm_checklists', 'route' => 'maintenance.checklists.index', 'icon' => 'list-checks', 'permission' => 'pm.view', 'module' => 'maintenance', 'staff' => true],
        ['title' => 'nav.holidays', 'route' => 'service.holidays.index', 'icon' => 'calendar-days', 'permission' => 'holiday.view', 'module' => 'service'],
        ['title' => 'nav.users', 'route' => 'identity.users.index', 'icon' => 'users', 'permission' => 'user.view'],
        ['title' => 'nav.roles', 'route' => 'identity.roles.index', 'icon' => 'shield-check', 'permission' => 'role.view'],
        ['title' => 'nav.tenants', 'route' => 'platform.impersonation.index', 'icon' => 'building-2', 'permission' => 'platform.impersonate'],
    ],
];
