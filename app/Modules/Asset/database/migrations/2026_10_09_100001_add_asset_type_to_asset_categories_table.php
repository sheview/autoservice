<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Hardware or software, per category: every asset takes the type of its category.
    // Existing categories become hardware (what the system held so far).
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->string('asset_type', 20)->default('hardware')->after('service_line');
        });
    }

    public function down(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('asset_type');
        });
    }
};
