<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pieces a technician took out of the customer's device on a job (the broken SSD, the old power
 * supply): what, its serial, what was wrong and what is done with it (sent for claim, given back
 * to the customer, kept in the store, thrown away). A note only: nothing follows from it and it
 * is never stock that can be used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_removed_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->string('serial_number', 100)->nullable();
            $table->text('problem')->nullable();
            $table->string('disposition', 20); // claim | return_customer | keep | discard
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        Rls::enable('ticket_removed_parts');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_removed_parts');
    }
};
