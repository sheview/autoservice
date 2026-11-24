<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The person's Keycloak account ("sub" of the ID token), on their main account only
            // (login_user_id null): SignInWithSso finds them by it, never by e-mail once linked.
            // NULLs do not clash in a MariaDB unique index.
            $table->string('keycloak_sub', 64)->nullable()->after('login_user_id');
            $table->unique('keycloak_sub');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['keycloak_sub']);
            $table->dropColumn('keycloak_sub');
        });
    }
};
