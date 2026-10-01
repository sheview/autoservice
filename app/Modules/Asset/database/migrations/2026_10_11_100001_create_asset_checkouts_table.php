<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // An asset handed to someone: "issue" (to use, no date to bring it back) or "loan" (with a
    // due date). Asked for, then approved or rejected; an approved one ends when the asset comes
    // back. Names are kept as they were at the time, so the paper trail never changes.
    public function up(): void
    {
        Schema::create('asset_checkouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('checkout_no', 30);
            $table->string('type', 10);
            $table->string('status', 20);

            // Who takes it: a user of the company, or someone from outside (name only).
            $table->foreignId('borrower_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('borrower_name');
            $table->string('borrower_department')->nullable();
            $table->string('borrower_phone', 50)->nullable();
            $table->text('purpose')->nullable();
            $table->date('due_on')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('returned_by_name')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'checkout_no']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_id']);
        });

        Rls::enable('asset_checkouts');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_checkouts');
    }
};
