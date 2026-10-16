<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt entered by mistake is voided by a new row ("void") that takes the stock back out and
 * points at the receipt it cancels; the ledger's rows themselves are never changed. Unique: a
 * receipt is voided once (several nulls are allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('voids_id')->nullable()->after('ticket_id')->constrained('stock_movements')->restrictOnDelete();
            $table->unique('voids_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['voids_id']);
            $table->dropUnique(['voids_id']);
            $table->dropColumn('voids_id');
        });
    }
};
