<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The receiver confirms each hand-out (ConfirmCheckoutReceipt): received_at / received_by when
 * they say they got it, receipt_issue when they say it was short or never came (the stock is told
 * at once). receipt_alerted_at: the "not confirmed yet" alert went out (NotifyCheckoutDelays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_fulfillments', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('stock_movement_id');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable()->after('received_by');
            $table->text('receipt_issue')->nullable()->after('received_by_name');
            $table->timestamp('receipt_alerted_at')->nullable()->after('receipt_issue');
        });

        // Hand-outs from before there was a confirmation count as received when handed out (no
        // one confirmed them, so no name): otherwise all of them would wait and alert at once.
        // Every company's rows on purpose: this is the one-off backfill of the new columns.
        DB::table('checkout_fulfillments')->whereNull('received_at')->update(['received_at' => DB::raw('fulfilled_at')]);
    }

    public function down(): void
    {
        Schema::table('checkout_fulfillments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['received_at', 'received_by_name', 'receipt_issue', 'receipt_alerted_at']);
        });
    }
};
