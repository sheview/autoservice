<?php

namespace App\Modules\Identity\Support;

/**
 * Every permission in the system ({resource}.{action}) and the default roles of a tenant, as
 * agreed in permissions.json. Each grant of a role carries a scope (DataScope):
 *
 *   all       every record of the company
 *   branch    records of the user's own branch (and those of no branch)
 *   own       records the user made, is assigned to or holds (each resource says which)
 *   customer  records of the customer the account belongs to (customer accounts only)
 *
 * Nothing is allowed unless granted. Permissions are global; roles are created per tenant from
 * DEFAULT_ROLES when the tenant is created, after which each tenant may change its own roles on
 * the roles matrix (except the admin role, which always holds everything). SyncPermissions adds
 * missing permission rows; `platform:sync-permissions --defaults` puts the default roles back.
 */
class PermissionCatalog
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_OWN = 'own';

    public const SCOPE_CUSTOMER = 'customer';

    /** Widest first: with several roles a user gets the widest scope any of them grants. */
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_BRANCH, self::SCOPE_CUSTOMER, self::SCOPE_OWN];

    public const PERMISSIONS = [
        'dashboard' => ['view'],
        'tickets' => ['view', 'create', 'update', 'assign', 'close', 'delete', 'export', 'approve'],
        'pm-visits' => ['view', 'create', 'update', 'complete', 'delete'],
        'pm-plans' => ['view', 'create', 'update', 'delete'],
        'pm-checklists' => ['view', 'manage'],
        'ip-check' => ['view', 'run'],
        'assets' => ['view', 'create', 'update', 'delete', 'export', 'import'],
        // Issue/loan requests (assets and parts, several lines): request = ask for oneself; create =
        // a request for anyone; approve = decide; fulfill = hand out (and backorder); return = take back.
        'asset-checkouts' => ['view', 'request', 'create', 'approve', 'fulfill', 'return'],
        'asset-categories' => ['view', 'manage'],
        'labels' => ['view', 'print'],
        'customers' => ['view', 'create', 'update', 'delete'],
        'contracts' => ['view', 'create', 'update', 'delete'],
        // issue = take parts (to a ticket, or on a part issue/loan form).
        'parts' => ['view', 'create', 'update', 'delete', 'issue', 'import', 'export'],
        // receive = mark an approved request ordered, then received.
        'purchase-requests' => ['view', 'create', 'update', 'approve', 'delete', 'receive'],
        'stock-movements' => ['view', 'create'],
        'reports' => ['view', 'export'],
        'summary-people' => ['view'],
        'summary-projects' => ['view'],
        'surveys' => ['view', 'respond', 'manage'],
        'users' => ['view', 'manage'],
        'roles' => ['manage'],
        'branches' => ['view', 'manage'],
        'holidays' => ['view', 'manage'],
        'company' => ['view', 'manage'],
        'alerts' => ['manage'],
        'activity-log' => ['view'],
        // Not in permissions.json: the company's manuals (links and files).
        'manuals' => ['view', 'manage'],
        // impersonate = enter a customer tenant; full_access = pass every check while inside.
        'platform' => ['impersonate', 'tenants', 'full_access'],
    ];

    public const SUPERADMIN = 'superadmin';

    public const CENTRAL_HELPDESK = 'central_helpdesk';

    public const CENTRAL_TECHNICIAN = 'central_technician';

    /**
     * Roles of the platform tenant. '*' = every permission. Central staff enter any customer
     * tenant (platform.impersonate) and work there with the tenant permissions listed here,
     * always over the whole company (scope all): the daily work, nothing that configures it.
     *
     * @var array<string, array{label: string, permissions: list<string>|string}>
     */
    public const PLATFORM_ROLES = [
        self::SUPERADMIN => [
            'label' => 'ผู้ดูแลแพลตฟอร์ม',
            'permissions' => '*',
        ],
        self::CENTRAL_HELPDESK => [
            'label' => 'Helpdesk ส่วนกลาง',
            'permissions' => [
                'platform.impersonate',
                'dashboard.view', 'branches.view',
                'tickets.view', 'tickets.create', 'tickets.update', 'tickets.assign',
                'pm-visits.view', 'pm-visits.update', 'pm-plans.view', 'pm-plans.update', 'pm-checklists.view',
                'ip-check.view', 'ip-check.run',
                'assets.view', 'asset-checkouts.view', 'asset-checkouts.create', 'asset-checkouts.fulfill', 'asset-checkouts.return', 'asset-categories.view', 'labels.view',
                'customers.view', 'contracts.view',
                'parts.view', 'stock-movements.view', 'purchase-requests.view', 'purchase-requests.create',
                'reports.view', 'summary-people.view', 'summary-projects.view', 'surveys.view', 'holidays.view', 'manuals.view',
            ],
        ],
        self::CENTRAL_TECHNICIAN => [
            'label' => 'ช่างส่วนกลาง',
            'permissions' => [
                'platform.impersonate',
                'dashboard.view', 'branches.view',
                'tickets.view', 'tickets.update', 'tickets.close',
                'pm-visits.view', 'pm-visits.update', 'pm-visits.complete', 'pm-plans.view', 'pm-checklists.view',
                'ip-check.view', 'ip-check.run',
                'assets.view', 'assets.update', 'asset-checkouts.view', 'asset-checkouts.request', 'asset-checkouts.return',
                'labels.view', 'labels.print',
                'customers.view', 'contracts.view',
                'parts.view', 'parts.issue', 'stock-movements.view', 'purchase-requests.view', 'purchase-requests.create',
                'manuals.view',
            ],
        ],
    ];

    /** The role of customer accounts (users with customer_id); the only role they may have. */
    public const CUSTOMER_ROLE = 'customer_it';

    /** The company's admin role: always every permission over the whole company. */
    public const ADMIN_ROLE = 'admin_company';

    /**
     * Roles seeded into every customer tenant (permissions.json). '*' = every permission except
     * platform.*, scope all. Otherwise permission => scope.
     *
     * @var array<string, array{label: string, grants: array<string, string>|string, external?: bool}>
     */
    public const DEFAULT_ROLES = [
        self::ADMIN_ROLE => [
            'label' => 'ผู้ดูแลระบบบริษัท',
            'grants' => '*',
        ],
        'helpdesk' => [
            'label' => 'เจ้าหน้าที่ Helpdesk',
            'grants' => [
                'dashboard.view' => 'all',
                'tickets.view' => 'all', 'tickets.create' => 'all', 'tickets.update' => 'all', 'tickets.assign' => 'all',
                'tickets.close' => 'all', 'tickets.export' => 'all',
                'pm-visits.view' => 'all', 'pm-visits.create' => 'all', 'pm-visits.update' => 'all',
                'pm-plans.view' => 'all', 'pm-plans.create' => 'all', 'pm-plans.update' => 'all',
                'pm-checklists.view' => 'all',
                'ip-check.view' => 'all',
                'assets.view' => 'all', 'assets.create' => 'all', 'assets.update' => 'all', 'assets.export' => 'all',
                'asset-checkouts.view' => 'all', 'asset-checkouts.create' => 'all', 'asset-checkouts.fulfill' => 'all', 'asset-checkouts.return' => 'all',
                'asset-categories.view' => 'all',
                'labels.view' => 'all', 'labels.print' => 'all',
                'customers.view' => 'all', 'customers.create' => 'all', 'customers.update' => 'all',
                'contracts.view' => 'all',
                'parts.view' => 'all', 'parts.create' => 'all', 'parts.update' => 'all', 'parts.issue' => 'all',
                'purchase-requests.view' => 'all', 'purchase-requests.create' => 'all', 'purchase-requests.update' => 'all',
                'stock-movements.view' => 'all', 'stock-movements.create' => 'all',
                'reports.view' => 'all',
                'summary-projects.view' => 'all',
                'surveys.view' => 'all',
                'holidays.view' => 'all',
                'manuals.view' => 'all',
            ],
        ],
        'technician' => [
            'label' => 'ช่างเทคนิค',
            'grants' => [
                'dashboard.view' => 'own',
                'tickets.view' => 'own', 'tickets.create' => 'own', 'tickets.update' => 'own',
                'pm-visits.view' => 'own', 'pm-visits.update' => 'own', 'pm-visits.complete' => 'own',
                'pm-plans.view' => 'branch',
                'pm-checklists.view' => 'all',
                'ip-check.view' => 'all', 'ip-check.run' => 'all',
                'assets.view' => 'branch',
                'asset-checkouts.view' => 'own', 'asset-checkouts.request' => 'own', 'asset-checkouts.return' => 'own',
                'labels.view' => 'all', 'labels.print' => 'all',
                'customers.view' => 'own',
                'contracts.view' => 'own',
                'parts.view' => 'all', 'parts.issue' => 'own',
                'purchase-requests.view' => 'own', 'purchase-requests.create' => 'own',
                'stock-movements.view' => 'own',
                'summary-people.view' => 'own',
                'surveys.view' => 'own',
                'manuals.view' => 'all',
            ],
        ],
        'user' => [
            'label' => 'ผู้ใช้งานทั่วไป',
            'grants' => [
                'dashboard.view' => 'own',
                'tickets.view' => 'own', 'tickets.create' => 'own',
                'assets.view' => 'own',
                'asset-checkouts.view' => 'own', 'asset-checkouts.request' => 'own',
                'manuals.view' => 'all',
            ],
        ],
        // Staff of a customer: only ever see records of their own customer.
        self::CUSTOMER_ROLE => [
            'label' => 'เจ้าหน้าที่ไอทีของลูกค้า',
            'external' => true,
            'grants' => [
                'dashboard.view' => 'customer',
                'tickets.view' => 'customer', 'tickets.create' => 'customer',
                // Not in permissions.json: confirming a repair is done, kept from before.
                'tickets.approve' => 'customer',
                'pm-visits.view' => 'customer', 'pm-plans.view' => 'customer',
                'assets.view' => 'customer',
                'contracts.view' => 'customer',
                'reports.view' => 'customer',
                'summary-projects.view' => 'customer',
                'surveys.view' => 'customer', 'surveys.respond' => 'customer',
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Permissions a customer tenant may grant (everything except platform.*).
     *
     * @return list<string>
     */
    public static function tenantPermissions(): array
    {
        return array_values(array_filter(self::all(), fn ($name) => ! str_starts_with($name, 'platform.')));
    }

    /**
     * The default grants of a company role: permission => scope.
     *
     * @return array<string, string>
     */
    public static function grantsFor(string $role): array
    {
        $grants = self::DEFAULT_ROLES[$role]['grants'];

        return $grants === '*' ? array_fill_keys(self::tenantPermissions(), self::SCOPE_ALL) : $grants;
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        return array_keys(self::grantsFor($role));
    }

    /**
     * @return list<string>
     */
    public static function platformPermissionsFor(string $role): array
    {
        $permissions = self::PLATFORM_ROLES[$role]['permissions'];

        return $permissions === '*' ? self::all() : $permissions;
    }
}
