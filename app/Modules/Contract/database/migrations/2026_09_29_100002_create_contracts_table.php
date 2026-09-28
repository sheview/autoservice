<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Unique per tenant including deleted contracts.
            $table->string('contract_no', 50);
            $table->string('title');
            $table->string('status', 20); // draft | active | cancelled
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('value')->nullable(); // satang
            $table->string('service_window', 10); // 8x5 | 12x6 | 24x7
            $table->unsignedSmallInteger('pm_interval_months')->nullable();
            $table->unsignedSmallInteger('notify_days_before')->default(60);
            // Set when the "about to expire" e-mail was sent; cleared when ends_on changes.
            $table->timestamp('expiry_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_no']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status', 'ends_on']);
        });
        Rls::enable('contracts');

        // Response / resolve time per ticket priority.
        Schema::create('contract_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('priority', 10); // critical | high | medium | low
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolve_minutes');
            $table->timestamps();

            $table->unique(['contract_id', 'priority']);
        });
        Rls::enable('contract_slas');

        // Assets covered by a contract (during the contract's period). An asset may be in many
        // contracts over time, e.g. this year's and the renewal.
        Schema::create('contract_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'asset_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        Rls::enable('contract_assets');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_assets');
        Schema::dropIfExists('contract_slas');
        Schema::dropIfExists('contracts');
    }
};
