<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets get a parent (a monitor under its PC, a battery cabinet under its UPS), the catalog brand /
 * model ids beside the brand / model text, and floor / room for grouping on site. The catalog is
 * filled in from the text already typed, tenant by tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('parent_asset_id')->nullable()->after('category_id')->constrained('assets')->nullOnDelete();
            $table->foreignId('asset_brand_id')->nullable()->after('brand')->constrained()->restrictOnDelete();
            $table->foreignId('asset_model_id')->nullable()->after('model')->constrained()->restrictOnDelete();
            $table->string('floor', 50)->nullable()->after('location');
            $table->string('room', 100)->nullable()->after('floor');

            $table->index(['tenant_id', 'parent_asset_id']);
        });

        $tenantIds = DB::table('assets')->whereNotNull('brand')->where('brand', '<>', '')->distinct()->pluck('tenant_id');
        foreach ($tenantIds as $tenantId) {
            $this->fillCatalog((int) $tenantId);
        }
    }

    private function fillCatalog(int $tenantId): void
    {
        $now = now();
        $brands = [];   // lower(name) => id
        $models = [];   // brand id | lower(name) => id

        DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('brand')->where('brand', '<>', '')
            ->orderBy('id')
            ->select(['id', 'brand', 'model', 'category_id'])
            ->chunk(500, function ($assets) use ($tenantId, $now, &$brands, &$models) {
                foreach ($assets as $asset) {
                    $brandName = trim($asset->brand);
                    if ($brandName === '') {
                        continue;
                    }
                    $brandKey = mb_strtolower($brandName);
                    $brands[$brandKey] ??= DB::table('asset_brands')->insertGetId([
                        'tenant_id' => $tenantId, 'name' => mb_substr($brandName, 0, 100), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $brandId = $brands[$brandKey];

                    $modelId = null;
                    $modelName = trim((string) $asset->model);
                    if ($modelName !== '') {
                        $modelKey = $brandId.'|'.mb_strtolower($modelName);
                        $models[$modelKey] ??= DB::table('asset_models')->insertGetId([
                            'tenant_id' => $tenantId, 'asset_brand_id' => $brandId, 'asset_category_id' => $asset->category_id,
                            'name' => mb_substr($modelName, 0, 150), 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $modelId = $models[$modelKey];
                    }

                    DB::table('assets')->where('tenant_id', $tenantId)->where('id', $asset->id)
                        ->update(['asset_brand_id' => $brandId, 'asset_model_id' => $modelId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['parent_asset_id']);
            $table->dropForeign(['asset_brand_id']);
            $table->dropForeign(['asset_model_id']);
            $table->dropIndex(['tenant_id', 'parent_asset_id']);
            $table->dropColumn(['parent_asset_id', 'asset_brand_id', 'asset_model_id', 'floor', 'room']);
        });
        // Every tenant's catalog was filled in by up(); empty it so up() can run again.
        DB::table('asset_models')->delete();
        DB::table('asset_brands')->delete();
    }
};
