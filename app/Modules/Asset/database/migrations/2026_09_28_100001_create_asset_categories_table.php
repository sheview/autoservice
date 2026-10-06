<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            // Asset codes of this category are "{code_prefix}-00001"; categories may share a prefix.
            $table->string('code_prefix', 10);
            $table->string('service_line')->nullable();
            // Extra fields (specs) of assets in this category: [{key, label, type, options, required}]
            $table->json('spec_fields')->default('[]');
            $table->timestamps();
            $table->softDeletes();
        });

        LiveUnique::add('asset_categories', ['tenant_id', 'name'], 'asset_categories_tenant_name_unique');
        Rls::enable('asset_categories');

        // Last running number of each code prefix (see GenerateAssetCode).
        Schema::create('asset_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('prefix', 10);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'prefix']);
        });

        Rls::enable('asset_code_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_code_sequences');
        Schema::dropIfExists('asset_categories');
    }
};
