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
| and the tenant has "module" switched on (if set; a list = all of them); "staff" items are hidden from customer
| accounts; "company" items are only shown inside a customer company (not the platform). "icon" is a lucide icon name that
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
        'reporting' => ['default' => true],
    ],

    // "group" = the sidebar section (a key of lang/{locale}/ui.php "nav_groups"); items of a
    // section stay together, in this order. No group = above the sections.
    'navigation' => [
        ['title' => 'nav.dashboard', 'route' => 'dashboard', 'icon' => 'layout-grid', 'permission' => 'dashboard.view'],

        ['group' => 'service', 'title' => 'nav.tickets', 'route' => 'service.tickets.index', 'icon' => 'wrench', 'permission' => 'tickets.view', 'module' => 'service'],
        ['group' => 'service', 'title' => 'nav.pm_visits', 'route' => 'maintenance.visits.index', 'icon' => 'calendar-check', 'permission' => 'pm-visits.view', 'module' => 'maintenance'],
        ['group' => 'service', 'title' => 'nav.pm_plans', 'route' => 'maintenance.plans.index', 'icon' => 'clipboard-list', 'permission' => 'pm-plans.view', 'module' => 'maintenance'],
        ['group' => 'service', 'title' => 'nav.pm_checklists', 'route' => 'maintenance.checklists.index', 'icon' => 'list-checks', 'permission' => 'pm-checklists.view', 'module' => 'maintenance', 'staff' => true],
        ['group' => 'service', 'title' => 'nav.ip_check', 'route' => 'asset.ip-check', 'icon' => 'network', 'permission' => 'ip-check.view', 'module' => 'asset', 'staff' => true],

        ['group' => 'assets', 'title' => 'nav.assets', 'route' => 'asset.assets.index', 'icon' => 'hard-drive', 'permission' => 'assets.view', 'module' => 'asset'],
        ['group' => 'assets', 'title' => 'nav.checkouts', 'route' => 'asset.requests.index', 'icon' => 'hand-helping', 'permission' => 'asset-checkouts.view', 'module' => 'asset', 'staff' => true],
        ['group' => 'assets', 'title' => 'nav.asset_categories', 'route' => 'asset.categories.index', 'icon' => 'tags', 'permission' => 'asset-categories.view', 'module' => 'asset'],
        ['group' => 'assets', 'title' => 'nav.labels', 'route' => 'labeling.labels.index', 'icon' => 'qr-code', 'permission' => 'labels.view', 'module' => 'labeling', 'staff' => true],

        ['group' => 'customers', 'title' => 'nav.customers', 'route' => 'contract.customers.index', 'icon' => 'briefcase', 'permission' => 'customers.view', 'module' => 'contract'],
        ['group' => 'customers', 'title' => 'nav.contracts', 'route' => 'contract.contracts.index', 'icon' => 'file-text', 'permission' => 'contracts.view', 'module' => 'contract'],

        ['group' => 'stock', 'title' => 'nav.parts', 'route' => 'inventory.parts.index', 'icon' => 'package', 'permission' => 'parts.view', 'module' => 'inventory', 'staff' => true],
        ['group' => 'stock', 'title' => 'nav.purchase_requests', 'route' => 'inventory.purchase-requests.index', 'icon' => 'shopping-cart', 'permission' => 'purchase-requests.view', 'module' => 'inventory', 'staff' => true],
        ['group' => 'stock', 'title' => 'nav.stock_movements', 'route' => 'inventory.movements.index', 'icon' => 'arrow-left-right', 'permission' => 'stock-movements.view', 'module' => 'inventory', 'staff' => true],

        ['group' => 'reports', 'title' => 'nav.reports', 'route' => 'reporting.reports.index', 'icon' => 'bar-chart-3', 'permission' => 'reports.view', 'module' => 'reporting'],
        ['group' => 'reports', 'title' => 'nav.people_summary', 'route' => 'reporting.people.index', 'icon' => 'user-search', 'permission' => 'summary-people.view', 'module' => 'reporting', 'staff' => true],
        ['group' => 'reports', 'title' => 'nav.project_summary', 'route' => 'reporting.projects.index', 'icon' => 'folder-kanban', 'permission' => 'summary-projects.view', 'module' => ['reporting', 'contract']],
        ['group' => 'reports', 'title' => 'nav.surveys', 'route' => 'survey.surveys.index', 'icon' => 'star', 'permission' => 'surveys.view', 'module' => 'survey'],

        ['group' => 'help', 'title' => 'nav.manuals', 'route' => 'document.manuals.index', 'icon' => 'book-open', 'permission' => 'manuals.view'],
        ['group' => 'settings', 'title' => 'nav.users', 'route' => 'identity.users.index', 'icon' => 'users', 'permission' => 'users.view'],
        ['group' => 'settings', 'title' => 'nav.roles', 'route' => 'identity.roles.index', 'icon' => 'shield-check', 'permission' => 'roles.manage'],
        ['group' => 'settings', 'title' => 'nav.holidays', 'route' => 'service.holidays.index', 'icon' => 'calendar-days', 'permission' => 'holidays.view', 'module' => 'service'],
        ['group' => 'settings', 'title' => 'nav.company', 'route' => 'tenancy.company.edit', 'icon' => 'building', 'permission' => 'company.view', 'company' => true],
        ['group' => 'settings', 'title' => 'nav.alerts', 'route' => 'platform.alerts.edit', 'icon' => 'bell', 'permission' => 'alerts.manage', 'company' => true],
        ['group' => 'settings', 'title' => 'nav.activity_log', 'route' => 'platform.activity-log', 'icon' => 'scroll-text', 'permission' => 'activity-log.view'],

        ['group' => 'platform', 'title' => 'nav.tenants', 'route' => 'platform.impersonation.index', 'icon' => 'building-2', 'permission' => 'platform.impersonate'],
        ['group' => 'platform', 'title' => 'nav.platform_settings', 'route' => 'platform.settings.edit', 'icon' => 'settings', 'permission' => 'platform.tenants'],
    ],
];
