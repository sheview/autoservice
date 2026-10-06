<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Row level security helpers for migrations.
 *
 * Every table that holds tenant data must call Rls::enable('table') right after it is created.
 * On PostgreSQL the policy compares tenant_id with the "app.tenant_id" setting that TenantContext
 * sets on the connection (no tenant set: no row matches). MariaDB has no row level security:
 * there the call does nothing and tenants are kept apart by the TenantScope of every model
 * (BelongsToTenant) and the tenant condition every raw query must carry.
 */
class Rls
{
    public const SETTING = 'app.tenant_id';

    /** Set only by App\Modules\Platform\CrossTenant\IdentityLookup (login, session, password reset). */
    public const IDENTITY_LOOKUP = 'app.identity_lookup';

    /**
     * @param  bool  $identityLookup  also allow rows while IdentityLookup is running (users table only)
     */
    public static function enable(string $table, bool $identityLookup = false): void
    {
        if (! self::supported()) {
            return;
        }

        $condition = "tenant_id = NULLIF(current_setting('".self::SETTING."', true), '')::bigint";

        if ($identityLookup) {
            $condition = "({$condition} OR current_setting('".self::IDENTITY_LOOKUP."', true) = 'on')";
        }

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$condition}) WITH CHECK ({$condition})");
    }

    /**
     * Lets a data migration (run by the table owner) read and write rows of every tenant, until
     * force() puts the policy back on the owner. Nothing to do without row level security.
     */
    public static function noForce(string $table): void
    {
        if (self::supported()) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }

    public static function force(string $table): void
    {
        if (self::supported()) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    /** Takes the policy off a table altogether (the users table, read before a tenant is known). */
    public static function disable(string $table): void
    {
        if (self::supported()) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    /** Whether the database has row level security (PostgreSQL only). */
    public static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
