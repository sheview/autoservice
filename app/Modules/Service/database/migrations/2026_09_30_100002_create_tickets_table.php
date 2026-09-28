<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            // TK-{Buddhist year}-{running number per tenant and year}
            $table->string('ticket_no', 30);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            // Contract that covered the job when it was opened; null = out of contract (no SLA).
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10); // critical | high | medium | low
            $table->string('status', 20);
            $table->string('source', 20); // phone | email | walk_in | portal
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            // SLA, copied from the contract when opened (so later contract edits do not move them).
            $table->string('service_window', 10)->nullable();
            $table->unsignedInteger('response_minutes')->nullable();
            $table->unsignedInteger('resolve_minutes')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolve_due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            // The resolve clock stops while on hold: hold_minutes adds up the business minutes spent
            // on hold, and resolve_due_at = created_at + resolve_minutes + hold_minutes (business time).
            $table->timestamp('on_hold_since')->nullable();
            $table->unsignedInteger('hold_minutes')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'ticket_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'resolve_due_at']);
        });
        Rls::enable('tickets');

        Schema::create('ticket_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year'); // Buddhist year
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
        });
        Rls::enable('ticket_number_sequences');

        // Timeline of a ticket: status changes, assignments and comments.
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Name at the time, so the timeline still reads right for users of other tenants
            // (an impersonating superadmin) or deleted users.
            $table->string('user_name')->nullable();
            $table->string('type', 20); // created | assigned | status | comment | updated
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('body')->nullable();
            // Internal notes are hidden from customer accounts (step 06b).
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
        Rls::enable('ticket_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_number_sequences');
        Schema::dropIfExists('tickets');
    }
};
