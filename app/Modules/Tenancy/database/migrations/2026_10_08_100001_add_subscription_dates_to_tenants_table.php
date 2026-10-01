<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // The period the customer company has paid for (both days included). Null = no limit
            // on that side. What happens around the dates is in App\Modules\Tenancy\Support\Subscription.
            $table->date('subscription_starts_on')->nullable()->after('plan');
            $table->date('subscription_ends_on')->nullable()->after('subscription_starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['subscription_starts_on', 'subscription_ends_on']);
        });
    }
};
