<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Row level security helpers for migrations.
 *
 * Every table that holds tenant data must call Rls::enable('table') right after it is created.
 * The policy compares tenant_id with the "app.tenant_id" setting that TenantContext sets on
 * the connection. When no tenant is set the setting is '' (or missing) and no row matches.
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
        $condition = "tenant_id = NULLIF(current_setting('".self::SETTING."', true), '')::bigint";

        if ($identityLookup) {
            $condition = "({$condition} OR current_setting('".self::IDENTITY_LOOKUP."', true) = 'on')";
        }

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$condition}) WITH CHECK ({$condition})");
    }
}
