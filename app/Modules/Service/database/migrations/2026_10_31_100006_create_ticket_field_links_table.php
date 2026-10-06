<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links that let someone without an account work on one ticket, from a phone or an iPad:
 *
 *   work  an outside technician (e.g. up-country): sees the job, fills in what was found and done,
 *         adds photos, has the customer sign on the screen or prints the job sheet to sign on
 *         paper and sends back a photo of it
 *   sign  the customer only signs off the job on the screen
 *
 * Nothing closes the job: the helpdesk checks what came back, closes it and prints the report.
 * Each link is one ticket only, until it expires, is revoked, or the job is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_field_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('mode', 10)->default('work'); // work | sign
            // Who it is for: the outside technician (or the customer for "sign").
            $table->string('holder_name');
            $table->string('holder_company')->nullable();
            $table->string('holder_phone', 50)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            // When what was filled in through the link was last sent back.
            $table->timestamp('submitted_at')->nullable();
            // The helpdesk has checked what came back (or closed the job).
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
        });
        DB::statement("ALTER TABLE ticket_field_links ADD CONSTRAINT ticket_field_links_mode_check CHECK (mode IN ('work', 'sign'))");
        Rls::enable('ticket_field_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_field_links');
    }
};
