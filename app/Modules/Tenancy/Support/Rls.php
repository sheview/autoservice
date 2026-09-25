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

    public static function enable(string $table): void
    {
        $current = "NULLIF(current_setting('".self::SETTING."', true), '')::bigint";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$table} USING (tenant_id = {$current}) WITH CHECK (tenant_id = {$current})"
        );
    }
}
