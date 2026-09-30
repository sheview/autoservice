<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The satisfaction survey of a closed ticket: one per ticket, answered once, either by a signed-in
// user on the ticket page or by whoever holds the public link (/s/{tenant ulid}/{token}).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            // Copied from the ticket when it was closed (a closed ticket no longer changes), so the
            // survey list can search and link without reading the Service module's table.
            $table->ulid('ticket_ulid');
            $table->string('ticket_no', 30);
            $table->string('ticket_title');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            // The technician who did the job.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // Secret of the public link; never shown to customer accounts of another customer.
            $table->string('token', 64)->unique();
            $table->unsignedTinyInteger('score')->nullable(); // 1-5
            $table->text('comment')->nullable();
            $table->timestamp('answered_at')->nullable();
            // Null when answered through the public link.
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('answered_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'answered_at']);
        });
        Rls::enable('ticket_surveys');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_surveys');
    }
};
