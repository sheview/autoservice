<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The customer ticked the score on the printed job sheet and staff keyed it in
    // (answered_by = who keyed it, answered_name = who rated).
    public function up(): void
    {
        Schema::table('ticket_surveys', function (Blueprint $table) {
            $table->boolean('on_paper')->default(false)->after('answered_name');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_surveys', function (Blueprint $table) {
            $table->dropColumn('on_paper');
        });
    }
};
