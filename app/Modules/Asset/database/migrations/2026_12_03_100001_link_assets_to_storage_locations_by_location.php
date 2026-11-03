<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assets imported before the import read their location as a storage location (as the form
 * does) were kept nowhere: the location filter missed them. Each is kept at the active storage
 * location its location names, within its own company. Every company's rows on purpose: a
 * one-off backfill, joined on tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update assets a
            join storage_locations s
              on s.tenant_id = a.tenant_id
             and s.name = trim(a.location)
             and s.is_active = 1
             and s.deleted_at is null
            set a.storage_location_id = s.id
            where a.storage_location_id is null
              and a.location is not null
            SQL);
    }

    public function down(): void
    {
        // Nothing to undo: the links are what the form would have set.
    }
};
