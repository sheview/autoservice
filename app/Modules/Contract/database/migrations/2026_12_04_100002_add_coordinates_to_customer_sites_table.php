<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer's site is: its province and its place on the map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_sites', function (Blueprint $table) {
            $table->string('province', 100)->nullable()->after('address');
            $table->decimal('lat', 10, 7)->nullable()->after('province');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('customer_sites', function (Blueprint $table) {
            $table->dropColumn(['province', 'lat', 'lng']);
        });
    }
};
