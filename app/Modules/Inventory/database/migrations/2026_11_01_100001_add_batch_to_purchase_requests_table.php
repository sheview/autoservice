<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several items asked for on one form: each is its own purchase request (bought, received and
 * handed out on its own), and they share a batch so they are shown and decided on together.
 * Empty for a request asked for alone, and on the requests before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->ulid('batch')->nullable()->after('pr_no')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('batch');
        });
    }
};
