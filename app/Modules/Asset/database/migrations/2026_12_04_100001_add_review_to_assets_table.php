<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment a technician registers on site (AddAssetFromSite) is kept with only what was typed
 * there: it waits for the office to fill in the rest (contracts, purchase, warranty) and confirm it
 * (needs_review). added_note says where it came from ("work order PM-..., by ...").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('notes');
            $table->string('added_note')->nullable()->after('needs_review');

            $table->index(['tenant_id', 'needs_review']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'needs_review']);
            $table->dropColumn(['needs_review', 'added_note']);
        });
    }
};
