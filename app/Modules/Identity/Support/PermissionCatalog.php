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
        'asset' => ['view', 'create', 'update', 'delete', 'import', 'export'],
        'asset_category' => ['view', 'create', 'update', 'delete'],
        'customer' => ['view', 'create', 'update', 'delete'],
        'contract' => ['view', 'create', 'update', 'delete'],
        'ticket' => ['view', 'create', 'update', 'assign', 'approve', 'close'],
        'holiday' => ['view', 'create', 'delete'],
        'pm' => ['view', 'create', 'update', 'delete', 'perform'],
        'sticker' => ['print'],
        'part' => ['view', 'create', 'update', 'delete', 'import', 'export'],
        'stock' => ['view', 'receive', 'issue', 'adjust'],
        'report' => ['view'],
        'platform' => ['impersonate', 'tenants'],
    ];

    /**
     * branch.all = may see data of every branch; without it a user only sees their own branch.
     */
    public const ALL_BRANCHES = 'branch.all';

    public const SUPERADMIN = 'superadmin';

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
            'permissions' => [
                'branch.view', 'branch.all',
                'asset.view', 'asset_category.view', 'customer.view', 'contract.view',
                'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign',
                'holiday.view',
                'pm.view', 'pm.create', 'pm.update',
                'part.view', 'stock.view',
                'user.view', 'report.view',
            ],
        ],
        'technician' => [
            'label' => 'ช่างเทคนิค',
            'permissions' => [
                'branch.view',
                'asset.view', 'asset.update',
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
            'permissions' => ['asset.view', 'ticket.view', 'ticket.create'],
        ],
        // Staff of a customer: only ever see records of their own customer (TenantPolicy).
        'customer' => [
            'label' => 'บัญชีลูกค้า',
            'permissions' => ['asset.view', 'ticket.view', 'ticket.create', 'ticket.approve', 'pm.view'],
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
}
