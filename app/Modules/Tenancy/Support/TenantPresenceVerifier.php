<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * The "exists" and "unique" validation rules, kept to the current tenant: a table with a
 * tenant_id column is only looked at for the tenant's own rows (none when no tenant is set),
 * so a form can never point at, or be refused because of, another company's record. Row level
 * security did this in the database on PostgreSQL; MariaDB has none.
 */
class TenantPresenceVerifier extends DatabasePresenceVerifier
{
    /** @var array<string, bool> table => has tenant_id */
    private static array $tenantTables = [];

    protected function table($table)
    {
        $query = parent::table($table);
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        if (self::$tenantTables[$name] ??= Schema::hasColumn($name, 'tenant_id')) {
            $tenantId = app(TenantContext::class)->id();
            $tenantId === null ? $query->whereRaw('1 = 0') : $query->where($name.'.tenant_id', $tenantId);
        }

        return $query;
    }
}
