<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unique index over the rows that are not soft deleted, for migrations. MariaDB has no partial
 * index ("... WHERE deleted_at IS NULL"), so the table gets a stored column "alive" that is 1 for
 * a live row and NULL for a deleted one, and the index takes it in: NULLs never collide, so a
 * deleted row frees its value. Text compares without case under the utf8mb4_unicode_ci collation,
 * as lower() did.
 */
class LiveUnique
{
    /**
     * @param  list<string>  $columns
     */
    public static function add(string $table, array $columns, string $name): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $name) {
            if (! Schema::hasColumn($table, 'alive')) {
                $blueprint->unsignedTinyInteger('alive')->nullable()->storedAs('IF(deleted_at IS NULL, 1, NULL)');
            }
            $blueprint->unique([...$columns, 'alive'], $name);
        });
    }
}
