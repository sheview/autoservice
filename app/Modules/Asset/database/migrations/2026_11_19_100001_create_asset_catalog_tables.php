<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of brands and models (category → brand → model), so a PM template can be tied to a brand
 * or a model. assets.brand / assets.model stay as the text shown; the ids point into this catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamps();
            $table->softDeletes();
        });

        LiveUnique::add('asset_brands', ['tenant_id', 'name'], 'asset_brands_tenant_name_unique');
        Rls::enable('asset_brands');

        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('asset_brand_id')->constrained()->restrictOnDelete();
            // The category the model belongs to (the first asset's when filled in from old data).
            $table->foreignId('asset_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->softDeletes();
        });

        LiveUnique::add('asset_models', ['tenant_id', 'asset_brand_id', 'name'], 'asset_models_tenant_brand_name_unique');
        Rls::enable('asset_models');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('asset_brands');
    }
};
