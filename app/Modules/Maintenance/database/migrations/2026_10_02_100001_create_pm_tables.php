<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What to check on an asset during PM, per asset category (null = any asset without its own).
        Schema::create('pm_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('asset_category_id')->nullable()->constrained()->restrictOnDelete();
            // [{key, label, type: check|text|number}] (dynamic per checklist)
            $table->jsonb('items')->default('[]');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'asset_category_id']);
        });
        Rls::enable('pm_checklists');

        // The PM schedule of one contract: its period is cut into rounds of interval_months.
        Schema::create('pm_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('interval_months');
            // Copied from the contract when the plan is created.
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contract_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('pm_plans');

        Schema::create('pm_visits', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // PM-{Buddhist year}-{running number per tenant and year}
            $table->string('visit_no', 30);
            $table->unsignedSmallInteger('round'); // 1, 2, ... within the plan
            // The round's period; the work is due by its end.
            $table->date('period_starts_on');
            $table->date('due_on');
            $table->date('scheduled_on')->nullable();
            $table->string('status', 20); // scheduled | in_progress | completed | cancelled
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'visit_no']);
            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['tenant_id', 'assignee_id']);
            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('pm_visits');

        Schema::create('pm_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year'); // Buddhist year
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
        });
        Rls::enable('pm_number_sequences');

        // One asset in a round: created when the round starts, with the checklist of that moment.
        Schema::create('pm_visit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('pm_checklist_id')->nullable()->constrained()->nullOnDelete();
            // Copy of the checklist items, so later checklist edits do not change old results.
            $table->jsonb('checklist')->default('[]');
            $table->string('result', 10); // pending | ok | issue | skipped
            $table->jsonb('answers')->default('{}'); // checklist key => answer
            $table->text('note')->nullable();
            // Repair ticket opened from this item (Service module).
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['pm_visit_id', 'asset_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        Rls::enable('pm_visit_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_visit_items');
        Schema::dropIfExists('pm_number_sequences');
        Schema::dropIfExists('pm_visits');
        Schema::dropIfExists('pm_plans');
        Schema::dropIfExists('pm_checklists');
    }
};
