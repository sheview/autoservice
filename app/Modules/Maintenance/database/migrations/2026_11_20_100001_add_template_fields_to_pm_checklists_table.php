<?php

use App\Modules\Tenancy\Support\LiveUnique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A checklist becomes a versioned PM template: one row per (code, version). A published version
 * is never edited again; a change is the next version (draft → published → archived). Work keeps
 * the copy of the version it was done with (pm_visit_items.checklist).
 *
 * - service_line: datacenter | network | pc (as asset_categories.service_line)
 * - layout: per_asset (a page per piece of equipment) | grouped_table (a row per piece)
 * - blocks: the shared blocks shown on the sheet, {name: bool}
 *
 * Checklists made before this are version 1 of a code of their own (CL{id}), already published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_checklists', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('tenant_id');
            $table->string('service_line', 20)->nullable()->after('name');
            $table->string('layout', 20)->default('per_asset')->after('service_line');
            $table->unsignedSmallInteger('version')->default(1)->after('layout');
            $table->string('status', 20)->default('published')->after('version');
            $table->json('blocks')->nullable()->after('items');
            $table->timestamp('published_at')->nullable()->after('status');
        });

        // Every tenant on purpose: each row takes a code of its own id.
        DB::table('pm_checklists')->update([
            'code' => DB::raw("CONCAT('CL', id)"),
            'published_at' => DB::raw('created_at'),
        ]);

        Schema::table('pm_checklists', function (Blueprint $table) {
            $table->string('code', 40)->nullable(false)->change();
            $table->index(['tenant_id', 'code']);
        });

        LiveUnique::add('pm_checklists', ['tenant_id', 'code', 'version'], 'pm_checklists_tenant_code_version_unique');
    }

    public function down(): void
    {
        Schema::table('pm_checklists', function (Blueprint $table) {
            $table->dropUnique('pm_checklists_tenant_code_version_unique');
            $table->dropIndex(['tenant_id', 'code']);
            $table->dropColumn(['alive', 'code', 'service_line', 'layout', 'version', 'status', 'blocks', 'published_at']);
        });
    }
};
