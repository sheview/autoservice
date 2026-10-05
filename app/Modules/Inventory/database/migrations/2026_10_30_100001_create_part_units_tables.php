<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts followed piece by piece by their serial number (track_serial). Most parts stay counted
 * by quantity only; for a tracked part every piece is a row of part_units, and its qty_on_hand is
 * always the number of pieces "in_stock" (recounted by RecordStockMovement, never typed).
 *
 * - part_categories: groups of parts; a category says whether its new parts are tracked.
 * - part_units: one piece: serial, cost, when and where it came from, warranty, status
 *   (in_stock | issued | removed) and the document it went out on.
 * - part_unit_events: every change of a piece, who made it, on which document, with the serial
 *   as it was then (documents print these, so a later correction does not change them).
 *
 * Every existing part starts untracked; nothing about the stock changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            // New parts of the category start tracked by serial (each part may still change it).
            $table->boolean('track_serial')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX part_categories_tenant_name_unique ON part_categories (tenant_id, lower(name)) WHERE deleted_at IS NULL');
        Rls::enable('part_categories');

        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('part_category_id')->nullable()->after('contract_id')->constrained()->nullOnDelete();
            $table->boolean('track_serial')->default(false)->after('unit');
        });

        Schema::create('part_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->unsignedBigInteger('unit_cost')->nullable(); // satang
            $table->date('received_on');
            // Where it came from: purchase (a purchase request), receive (received directly), backfill
            // (in stock already when the part started to be tracked).
            $table->string('source', 20);
            $table->foreignId('purchase_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier')->nullable(); // supplier / delivery note, as typed
            $table->date('warranty_until')->nullable();
            $table->string('status', 20)->default('in_stock'); // in_stock | issued | removed
            // Where it is now when issued: the job, the device it went into, the request line.
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checkout_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'part_id', 'status']);
            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        // One serial per part of the tenant (ignoring case and spaces around it).
        DB::statement('CREATE UNIQUE INDEX part_units_tenant_part_serial_unique ON part_units (tenant_id, part_id, lower(serial_number)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX part_units_tenant_serial_index ON part_units (tenant_id, lower(serial_number))');
        DB::statement("ALTER TABLE part_units ADD CONSTRAINT part_units_status_check CHECK (status IN ('in_stock', 'issued', 'removed'))");
        Rls::enable('part_units');

        // Rows are only ever added.
        Schema::create('part_unit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('part_unit_id')->constrained()->restrictOnDelete();
            // receive | backfill | issue | return | remove | correct
            $table->string('action', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('serial_number', 100); // as it was then
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checkout_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checkout_fulfillment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'part_unit_id']);
            $table->index(['tenant_id', 'checkout_item_id']);
            $table->index(['tenant_id', 'stock_movement_id']);
        });
        Rls::enable('part_unit_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('part_unit_events');
        Schema::dropIfExists('part_units');
        Schema::table('parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_category_id');
            $table->dropColumn('track_serial');
        });
        Schema::dropIfExists('part_categories');
    }
};
