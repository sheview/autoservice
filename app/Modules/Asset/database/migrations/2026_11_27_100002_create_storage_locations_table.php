<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the company keeps its things (main store, shelf A-1, ...): one list for assets and parts
 * (parts.storage_location_id comes in the Inventory module). Apart from assets.location, which is
 * where a device is installed at the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('notes', 255)->nullable();
            // Off = no longer offered for new picks; what is already there keeps it.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        LiveUnique::add('storage_locations', ['tenant_id', 'name'], 'storage_locations_tenant_name_unique');
        Rls::enable('storage_locations');

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('storage_location_id')->nullable()->after('room')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_location_id');
        });
        Schema::dropIfExists('storage_locations');
    }
};
