<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // A company made for training (SampleCompanySeeder): a banner on every page, and no
            // e-mail, LINE or Telegram message ever leaves it.
            $table->boolean('is_sample')->default(false)->after('is_platform');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_sample');
        });
    }
};
