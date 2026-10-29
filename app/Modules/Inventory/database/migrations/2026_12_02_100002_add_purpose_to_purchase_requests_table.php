<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each item bought says what it is for (ItemPurpose), for which customer and on which ticket.
 * Replacing a broken unit keeps its serial number or a photo (media collection "replaced_photo").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->after('reason');
            $table->string('purpose_other')->nullable()->after('purpose');
            // Used inside the company (no customer), or for a customer and one of its projects (contract_id).
            $table->boolean('for_internal')->default(false)->after('purpose_other');
            $table->string('customer_name')->nullable()->after('for_internal');
            $table->foreignId('ticket_id')->nullable()->after('customer_name')->constrained()->nullOnDelete();
            $table->string('replaced_serial', 100)->nullable()->after('ticket_id');

            $table->index(['tenant_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'ticket_id']);
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropColumn(['purpose', 'purpose_other', 'for_internal', 'customer_name', 'replaced_serial']);
        });
    }
};
