<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What customers see of a ticket without signing in, and what a phone adds when closing it:
 *   tracking_token   the secret of its tracking link (/track/{token}); a new one revokes the old
 *   customer_message what the office tells the customer (shown on the tracking page)
 *   contact_email    the reporter's e-mail (a report made with the QR form)
 *   closed_lat/lng   where the technician was when they finished (if the phone allowed it)
 *   reporter_anonymized_at  when the reporter's name, phone and e-mail were blanked out
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique()->after('ulid');
            $table->text('customer_message')->nullable();
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->decimal('closed_lat', 10, 7)->nullable();
            $table->decimal('closed_lng', 10, 7)->nullable();
            $table->timestamp('reporter_anonymized_at')->nullable();
        });

        // Every ticket there is gets its link. Runs across tenants: past row level security meanwhile.
        Rls::noForce('tickets');
        foreach (DB::table('tickets')->whereNull('tracking_token')->pluck('id') as $id) {
            DB::table('tickets')->where('id', $id)->update(['tracking_token' => Str::random(40)]);
        }
        Rls::force('tickets');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn(['tracking_token', 'customer_message', 'contact_email', 'closed_lat', 'closed_lng', 'reporter_anonymized_at']);
        });
    }
};
