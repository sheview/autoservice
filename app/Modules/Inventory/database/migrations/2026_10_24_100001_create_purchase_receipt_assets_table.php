<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery may become several assets: one per serial number in a category counted by serial
 * (each device its own code, label and history), or one asset holding a quantity otherwise.
 * The assets of each receipt move here from purchase_receipts.asset_id, which goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['purchase_receipt_id', 'asset_id']);
        });
        Rls::enable('purchase_receipt_assets');

        // Runs across every tenant: let the owner past row level security meanwhile.
        DB::statement('ALTER TABLE purchase_receipts NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE purchase_receipt_assets NO FORCE ROW LEVEL SECURITY');
        DB::statement('INSERT INTO purchase_receipt_assets (tenant_id, purchase_receipt_id, asset_id, quantity, created_at, updated_at)
            SELECT tenant_id, id, asset_id, quantity, registered_at, registered_at FROM purchase_receipts WHERE asset_id IS NOT NULL');
        DB::statement('ALTER TABLE purchase_receipts FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE purchase_receipt_assets FORCE ROW LEVEL SECURITY');

        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('registered_as')->constrained()->nullOnDelete();
        });
        DB::statement('ALTER TABLE purchase_receipts NO FORCE ROW LEVEL SECURITY');
        DB::statement('UPDATE purchase_receipts r SET asset_id = (SELECT min(a.asset_id) FROM purchase_receipt_assets a WHERE a.purchase_receipt_id = r.id)');
        DB::statement('ALTER TABLE purchase_receipts FORCE ROW LEVEL SECURITY');
        Schema::dropIfExists('purchase_receipt_assets');
    }
};
