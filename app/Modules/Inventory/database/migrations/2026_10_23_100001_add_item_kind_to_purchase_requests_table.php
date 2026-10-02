<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a purchase becomes once it arrives, so deliveries go into the system on their own: an
 * asset of a category, or stock of a part. Set by the requester when known, otherwise by the
 * buyer on the first delivery (and kept for the next ones). Empty on the requests before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('item_kind', 10)->nullable()->after('unit');
            $table->foreignId('asset_category_id')->nullable()->after('item_kind')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_category_id');
            $table->dropColumn('item_kind');
        });
    }
};
