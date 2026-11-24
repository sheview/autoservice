<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Where the company's SSO accounts live (Tenant::SSO_*): accounts in Keycloak itself, or
            // the company's own system (AD/Entra/...) brokered by a Keycloak Organization.
            $table->string('sso_source', 16)->default('keycloak')->after('auth_mode');
            // That Organization's alias: a sign-in counts for this company only when Keycloak says
            // it came through it (SignInWithSso). NULLs do not clash in a MariaDB unique index.
            $table->string('keycloak_org', 64)->nullable()->after('sso_source');
            $table->unique('keycloak_org');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['keycloak_org']);
            $table->dropColumn(['sso_source', 'keycloak_org']);
        });
    }
};
