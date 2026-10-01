<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A short name for small spaces such as QR labels (e.g. "สป.พลังงาน"); empty = use the full name.
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('short_name', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
    }
};
