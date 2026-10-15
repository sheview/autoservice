<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which template (by code, so always its newest published version) an asset is checked with.
 * PmTemplateResolver picks the most specific match: asset > model > brand + category > category >
 * the default row (every column empty).
 *
 * Filled in from pm_checklists.asset_category_id (the one checklist per category until now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_template_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('template_code', 40);
            $table->foreignId('asset_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('asset_brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('asset_model_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'template_code']);
        });
        Rls::enable('pm_template_assignments');

        // Every tenant on purpose: each checklist keeps covering the category it covered.
        $now = now();
        DB::table('pm_checklists')->whereNull('deleted_at')->orderBy('id')
            ->select(['tenant_id', 'code', 'asset_category_id'])
            ->chunk(500, function ($checklists) use ($now) {
                DB::table('pm_template_assignments')->insert($checklists->map(fn ($checklist) => [
                    'tenant_id' => $checklist->tenant_id,
                    'template_code' => $checklist->code,
                    'asset_category_id' => $checklist->asset_category_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_template_assignments');
    }
};
