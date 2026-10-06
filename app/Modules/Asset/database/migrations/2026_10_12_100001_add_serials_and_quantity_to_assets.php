<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An asset holds one or more serial numbers (asset_serials) and a quantity: for a category that
 * requires serials the quantity is the number of serials, otherwise it is typed in with a unit
 * (cables, connectors and other things bought by the lot). Plus the sub-type of the old Excel file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('subtype', 100)->nullable()->after('model');
            $table->unsignedInteger('quantity')->default(1)->after('serial_number');
            $table->string('unit', 30)->nullable()->after('quantity');
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            // Devices (notebook, switch, UPS...) must have at least one serial number.
            $table->boolean('requires_serial')->default(false)->after('asset_type');
        });

        Schema::create('asset_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('serial_number');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'asset_id']);
        });

        // A serial number belongs to one asset of the tenant (ignoring case); a removed one can be used again.
        LiveUnique::add('asset_serials', ['tenant_id', 'serial_number'], 'asset_serials_tenant_serial_unique');

        // FORCE ROW LEVEL SECURITY applies to the table owner too, and no tenant is set while migrating:
        // lift it for the copy so every tenant's rows are seen, then put it back.
        Rls::noForce('assets');
        Rls::noForce('asset_categories');

        DB::statement("UPDATE asset_categories SET requires_serial = true WHERE asset_type = 'hardware'");
        DB::statement(<<<'SQL'
            INSERT INTO asset_serials (tenant_id, asset_id, serial_number, created_at, updated_at)
            SELECT tenant_id, id, trim(serial_number), now(), now()
            FROM assets
            WHERE deleted_at IS NULL AND trim(coalesce(serial_number, '')) <> ''
            SQL);

        Rls::force('assets');
        Rls::force('asset_categories');

        Rls::enable('asset_serials');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_serials');

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('requires_serial');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['subtype', 'quantity', 'unit']);
        });
    }
};
