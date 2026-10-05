<?php

use App\Modules\Asset\Support\AssetKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A short random key printed in each asset's QR code (/q/{asset code}?k={key}). Asset codes run in
 * order (NB-00001, NB-00002, ...), so without the key anyone could walk through a company's devices
 * on the public page; with it, only who holds the label can. Staff signed in do not need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('public_key', 16)->nullable()->after('ulid');
            $table->unique(['tenant_id', 'public_key']);
        });

        // Every asset there is gets its key. Runs across tenants: past row level security meanwhile.
        DB::statement('ALTER TABLE assets NO FORCE ROW LEVEL SECURITY');
        foreach (DB::table('assets')->whereNull('public_key')->pluck('id') as $id) {
            DB::table('assets')->where('id', $id)->update(['public_key' => AssetKey::make()]);
        }
        DB::statement('ALTER TABLE assets FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'public_key']);
            $table->dropColumn('public_key');
        });
    }
};
