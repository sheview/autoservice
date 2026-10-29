<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each line says what it is for (ItemPurpose), for which customer and on which ticket, so the
 * lines of one request can serve different jobs. Replacing a broken unit keeps its serial number
 * or a photo (replaced_photo_id: a media row of the request, collection "replaced_photos").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->after('checkout_type');
            $table->string('purpose_other')->nullable()->after('purpose');
            // Used inside the company (no customer), or for a customer and one of its projects.
            $table->boolean('for_internal')->default(false)->after('purpose_other');
            $table->string('customer_name')->nullable()->after('for_internal');
            $table->foreignId('contract_id')->nullable()->after('customer_name')->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->after('contract_id')->constrained()->nullOnDelete();
            $table->string('replaced_serial', 100)->nullable()->after('ticket_id');
            $table->unsignedBigInteger('replaced_photo_id')->nullable()->after('replaced_serial');

            $table->index(['tenant_id', 'ticket_id']);
        });

        // Lines from before take the ticket, project and customer ("department") of their request,
        // each from its own company's request. Every company's rows on purpose: a one-off backfill.
        DB::table('checkout_items')->update([
            'contract_id' => DB::raw('(select r.contract_id from checkout_requests r where r.id = checkout_items.request_id and r.tenant_id = checkout_items.tenant_id)'),
            'ticket_id' => DB::raw('(select r.ticket_id from checkout_requests r where r.id = checkout_items.request_id and r.tenant_id = checkout_items.tenant_id)'),
            'customer_name' => DB::raw('(select r.borrower_department from checkout_requests r where r.id = checkout_items.request_id and r.tenant_id = checkout_items.tenant_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('checkout_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'ticket_id']);
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['purpose', 'purpose_other', 'for_internal', 'customer_name', 'replaced_serial', 'replaced_photo_id']);
        });
    }
};
