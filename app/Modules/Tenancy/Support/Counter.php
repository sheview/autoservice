<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Running numbers kept in a counter table of (tenant_id, key, last_number), with a unique index
 * on (tenant_id, key). next() bumps the row in one upsert and reads it back in the same
 * transaction: the upsert holds the row lock until the transaction ends, so two requests at the
 * same time never get the same number. Always with the tenant's own condition.
 */
class Counter
{
    public static function next(string $table, string $keyColumn, string|int $key, int $tenantId): int
    {
        return DB::transaction(function () use ($table, $keyColumn, $key, $tenantId) {
            DB::statement(
                "insert into {$table} (tenant_id, {$keyColumn}, last_number, created_at, updated_at)
                 values (?, ?, 1, now(), now())
                 on duplicate key update last_number = last_number + 1, updated_at = now()",
                [$tenantId, $key],
            );

            return (int) DB::table($table)->where('tenant_id', $tenantId)->where($keyColumn, $key)->value('last_number');
        });
    }

    /**
     * Makes whoever numbers something of the tenant wait for the other (the tenant's row is locked
     * until the transaction ends). Call inside the transaction that saves the numbered record.
     */
    public static function lockTenant(int $tenantId): void
    {
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');
    }
}
