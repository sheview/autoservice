<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue/loan requests with several lines (checkout-schema.md): one request is approved at once
 * (lines may be cut down or rejected with a reason), each line is handed out on its own, partly
 * if need be; what cannot be handed out is backordered, never lost. Lines are assets (lent or
 * issued, returned with their condition) or parts (issued against a ticket, out of stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('request_no', 30);
            // draft | pending | approved | partial | fulfilled | closed | rejected | cancelled
            $table->string('status', 20);
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name')->nullable();
            // Who receives the things: a user of the company, or someone from outside by name.
            $table->foreignId('borrower_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('borrower_name');
            $table->string('borrower_department')->nullable();
            $table->string('borrower_phone', 50)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->text('purpose')->nullable();
            $table->date('needed_by')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('auto_approved')->default(false);
            $table->text('reject_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // When the "waiting for approval too long" alert went out (once per request).
            $table->timestamp('approval_alerted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'request_no']);
            $table->index(['tenant_id', 'status', 'needed_by']);
            $table->index(['tenant_id', 'requester_id']);
            $table->index(['tenant_id', 'borrower_user_id']);
            $table->index(['tenant_id', 'contract_id']);
        });
        Rls::enable('checkout_requests');

        Schema::create('checkout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('checkout_requests')->cascadeOnDelete();
            $table->string('item_type', 10); // asset | part
            $table->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->restrictOnDelete();
            // What it was when asked for (the part lives in the Inventory module).
            $table->string('item_code', 50)->nullable();
            $table->string('item_name');
            $table->string('unit', 30)->nullable();
            // issue (handed over to use; parts are used up) | loan (an asset, back by due_return_date)
            $table->string('checkout_type', 10)->default('issue');
            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_approved')->nullable();
            $table->unsignedInteger('qty_fulfilled')->default(0);
            $table->unsignedInteger('qty_returned')->default(0);
            // pending | approved | partial | fulfilled | backordered | rejected | cancelled
            $table->string('status', 20);
            $table->date('due_return_date')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('returned_by_name')->nullable();
            $table->string('return_condition')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('backorder_alerted_at')->nullable();
            $table->timestamp('overdue_alerted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_id', 'status']);
            $table->index(['tenant_id', 'part_id', 'status']);
        });
        Rls::enable('checkout_items');

        Schema::create('checkout_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('checkout_items')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fulfilled_by_name')->nullable();
            $table->timestamp('fulfilled_at');
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Rls::enable('checkout_fulfillments');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_fulfillments');
        Schema::dropIfExists('checkout_items');
        Schema::dropIfExists('checkout_requests');
    }
};
