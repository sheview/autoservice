<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // How the company's staff sign in (Tenant::AUTH_*): password, both, or Keycloak SSO only.
            // Existing companies keep signing in with a password.
            $table->string('auth_mode', 16)->default('password')->after('is_sample');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('auth_mode');
        });
    }
};
