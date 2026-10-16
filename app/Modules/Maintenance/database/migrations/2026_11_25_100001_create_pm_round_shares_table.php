<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer's link to a round report (PmRoundReport): the approved work orders of a
        // customer (or one plan) due within a period, read when the link is opened. Only the
        // SHA-256 of the token is kept, as pm_report_shares.
        Schema::create('pm_round_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('pm_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('from_on');
            $table->date('to_on');
            $table->char('token_hash', 64)->unique();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('pm_round_shares');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_round_shares');
    }
};
