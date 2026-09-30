<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Spare parts of the MA company (the tenant) and the ledger of every stock change.
// One stock per tenant: there are no warehouses or per-branch balances.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('part_number', 100)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('unit', 30);
            // Reorder point: the part counts as "low" when qty_on_hand <= min_qty (0 = not watched).
            $table->unsignedInteger('min_qty')->default(0);
            $table->unsignedBigInteger('unit_cost')->nullable(); // satang, cost of the last receipt
            // Kept in step with stock_movements by RecordStockMovement; never edited directly.
            $table->integer('qty_on_hand')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        // Code is what people type and search, so it is unique per tenant (ignoring case).
        DB::statement('CREATE UNIQUE INDEX parts_tenant_code_unique ON parts (tenant_id, lower(code)) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE parts ADD CONSTRAINT parts_qty_on_hand_check CHECK (qty_on_hand >= 0)');
        Rls::enable('parts');

        // Ledger: rows are only ever added, a mistake is corrected with another movement.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // receive | issue | return | adjust
            $table->integer('quantity'); // signed: + into stock, - out of stock
            $table->integer('balance_after');
            $table->unsignedBigInteger('unit_cost')->nullable(); // satang, on receipts
            // The job the part was used on (issue / return from a ticket).
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable(); // delivery note, PO number, ...
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Name at the time, so the ledger still reads right for deleted users or an
            // impersonating superadmin.
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index(['part_id', 'id']);
            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'type']);
        });
        Rls::enable('stock_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('parts');
    }
};
