<?php

namespace App\Modules\Identity\Support;

/**
 * Every permission in the system ({module}.{action}) and the default roles of a tenant.
 *
 * Permissions are global; roles are created per tenant from DEFAULT_ROLES when the tenant
 * is created, after which each tenant may change its own roles.
 * Add permissions here when a module needs them; SyncPermissions adds missing rows.
 */
class PermissionCatalog
{
    public const PERMISSIONS = [
        'user' => ['view', 'create', 'update'],
        'role' => ['view', 'create', 'update'],
        'branch' => ['view', 'create', 'update', 'delete', 'all'],
        // checkout = ask to issue/lend an asset and take it back; approve = decide on those requests.
        'asset' => ['view', 'create', 'update', 'delete', 'import', 'export', 'checkout', 'approve'],
        'asset_category' => ['view', 'create', 'update', 'delete'],
        'customer' => ['view', 'create', 'update', 'delete'],
        'contract' => ['view', 'create', 'update', 'delete'],
        'ticket' => ['view', 'create', 'update', 'assign', 'approve', 'close'],
        'holiday' => ['view', 'create', 'delete'],
        'pm' => ['view', 'create', 'update', 'delete', 'perform'],
        'sticker' => ['print'],
        'part' => ['view', 'create', 'update', 'delete', 'import', 'export'],
        'stock' => ['view', 'receive', 'issue', 'adjust'],
        'survey' => ['view', 'answer'],
        'report' => ['view'],
        // Ask to buy something the company does not have. Approved with asset.approve; marked
        // ordered and received with stock.receive (Inventory module).
        'purchase' => ['request'],
        // The company's own profile: logo, service phone and e-mail (labels, documents).
        'company' => ['update'],
        // impersonate = enter a customer tenant; full_access = pass every check while inside.
        'platform' => ['impersonate', 'tenants', 'full_access'],
    ];

    /**
     * branch.all = may see data of every branch; without it a user only sees their own branch.
     */
    public const ALL_BRANCHES = 'branch.all';

    public const SUPERADMIN = 'superadmin';

    public const CENTRAL_HELPDESK = 'central_helpdesk';

    public const CENTRAL_TECHNICIAN = 'central_technician';

    /**
     * Roles of the platform tenant. '*' = every permission. Central staff enter any customer
     * tenant (platform.impersonate) and work there with the tenant permissions listed here:
     * they see every branch and do the daily work, but hold nothing that configures the
     * company (users, roles, branches, categories, checklists, holidays, imports, modules).
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
                'branch.view', 'branch.all',
                'asset.view', 'asset.checkout', 'purchase.request', 'asset_category.view', 'customer.view', 'contract.view',
                'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign',
                'holiday.view',
                'pm.view', 'pm.update',
                'part.view', 'stock.view',
                'survey.view', 'report.view',
            ],
        ],
        self::CENTRAL_TECHNICIAN => [
            'label' => 'ช่างส่วนกลาง',
            'permissions' => [
                'platform.impersonate',
                'branch.view', 'branch.all',
                'asset.view', 'asset.update', 'asset.checkout', 'purchase.request',
                'customer.view', 'contract.view',
                'ticket.view', 'ticket.update', 'ticket.close',
                'pm.view', 'pm.perform',
                'sticker.print',
                'part.view', 'stock.view', 'stock.issue',
            ],
        ],
    ];

    /** The role of customer accounts (users with customer_id); the only role they may have. */
    public const CUSTOMER_ROLE = 'customer';

    /**
     * Roles seeded into every customer tenant. '*' = every permission except platform.*.
     *
     * @var array<string, array{label: string, permissions: list<string>|string}>
     */
    public const DEFAULT_ROLES = [
        'admin_company' => [
            'label' => 'ผู้ดูแลระบบบริษัท',
            'permissions' => '*',
        ],
        'helpdesk' => [
            'label' => 'เจ้าหน้าที่ Helpdesk',
            // No branch.all: like every company role but the admin, helpdesk works in its own branch.
            'permissions' => [
                'branch.view',
                'asset.view', 'asset.checkout', 'purchase.request', 'asset_category.view', 'customer.view', 'contract.view',
                'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign',
                'holiday.view',
                'pm.view', 'pm.create', 'pm.update',
                'part.view', 'stock.view',
                'survey.view',
                'user.view', 'report.view',
            ],
        ],
        'technician' => [
            'label' => 'ช่างเทคนิค',
            'permissions' => [
                'branch.view',
                'asset.view', 'asset.update', 'asset.checkout', 'purchase.request',
                'customer.view', 'contract.view',
                'ticket.view', 'ticket.update', 'ticket.close',
                'pm.view', 'pm.perform',
                'sticker.print',
                // A technician takes parts for the jobs they work on; receiving and counting is office work.
                'part.view', 'stock.view', 'stock.issue',
            ],
        ],
        'user' => [
            'label' => 'ผู้ใช้งานทั่วไป',
            'permissions' => ['asset.view', 'ticket.view', 'ticket.create', 'purchase.request'],
        ],
        // Staff of a customer: only ever see records of their own customer (TenantPolicy).
        'customer' => [
            'label' => 'บัญชีลูกค้า',
            'permissions' => ['asset.view', 'ticket.view', 'ticket.create', 'ticket.approve', 'pm.view', 'survey.answer'],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];
        foreach (self::PERMISSIONS as $module => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$module}.{$action}";
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
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $permissions = self::DEFAULT_ROLES[$role]['permissions'];

        return $permissions === '*' ? self::tenantPermissions() : $permissions;
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
