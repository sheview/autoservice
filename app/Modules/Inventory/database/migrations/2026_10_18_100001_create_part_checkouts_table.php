<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issuing and lending spare parts, like assets (asset_checkouts): asked for, approved (the stock
 * goes out then), and for a loan taken back. Parts also get the MA contract (project) they are
 * kept for, set when the part is registered; a form starts from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'contract_id']);
        });

        Schema::create('part_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('ulid')->unique();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('checkout_no', 30);
            $table->string('type', 10); // issue | loan
            $table->unsignedInteger('quantity');
            $table->string('status', 20); // pending | approved | rejected | returned | cancelled
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
            $table->index(['tenant_id', 'part_id', 'status']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'contract_id']);
        });
        Rls::enable('part_checkouts');

        // Running numbers of the Inventory module ("PC-2569" for part forms).
        Schema::create('inventory_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('prefix', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'prefix']);
        });
        Rls::enable('inventory_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sequences');
        Schema::dropIfExists('part_checkouts');
        Schema::table('parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
