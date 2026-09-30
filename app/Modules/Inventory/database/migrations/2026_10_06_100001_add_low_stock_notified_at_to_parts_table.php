<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            // Set when the "low stock" e-mail was sent; cleared when the stock is back above the
            // reorder point or the reorder point changes, so the part is e-mailed once per shortage.
            $table->timestamp('low_stock_notified_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('low_stock_notified_at');
        });
    }
};
