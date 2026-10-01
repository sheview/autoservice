<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A request to buy something the company does not have: asked for, approved or rejected,
    // ordered, received. Names are kept as they were at the time. Prices in satang.
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('pr_no', 30);
            $table->string('status', 20);

            $table->string('item_name');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 30);
            $table->bigInteger('unit_price')->nullable(); // estimate, satang
            // Product pages to buy from: a list of URLs.
            $table->jsonb('links')->default('[]');
            $table->text('reason')->nullable();
            $table->date('needed_by')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('ordered_by_name')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->text('order_note')->nullable();
            $table->string('received_by_name')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('receive_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'pr_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'requested_by']);
        });

        Rls::enable('purchase_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
