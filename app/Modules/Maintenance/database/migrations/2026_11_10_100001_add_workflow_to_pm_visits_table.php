<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PM rounds become work orders with an approval workflow (PmWorkflow):
 *   draft → scheduled → in_progress → submitted → pending_approval → approved → published → closed
 * with revision_required (sent back to the technician) and cancelled.
 *
 * - A work order may stand on its own (made by hand, not cut from a plan): plan, contract, round
 *   and period become optional, and it has its own title.
 * - Who moved it through each step, and when (completed_at stays "field work handed in").
 * - Rounds already completed before this workflow existed become closed: their work was done
 *   and nobody approves them after the fact. Their report has no stored version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->foreignId('pm_plan_id')->nullable()->change();
            $table->foreignId('contract_id')->nullable()->change();
            $table->unsignedSmallInteger('round')->nullable()->change();
            $table->date('period_starts_on')->nullable()->change();

            $table->string('title')->nullable()->after('visit_no');
            $table->foreignId('created_by')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('submitted_by');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('approved_by');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('published_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            // Why it was last sent back, and how many times.
            $table->text('revision_note')->nullable()->after('summary');
            $table->unsignedSmallInteger('revision_count')->default(0)->after('revision_note');
            // The newest approved report (pm_report_versions.version), and the one the customer was
            // given (published); a correction approved later reaches the customer when published. 0 = none.
            $table->unsignedSmallInteger('current_version')->default(0)->after('revision_count');
            $table->unsignedSmallInteger('published_version')->default(0)->after('current_version');
        });

        // Every tenant on purpose: a change of the status values, not of anyone's data.
        DB::table('pm_visits')->where('status', 'completed')->update([
            'status' => 'closed',
            'closed_at' => DB::raw('completed_at'),
        ]);
    }

    public function down(): void
    {
        DB::table('pm_visits')->whereIn('status', ['submitted', 'pending_approval', 'approved', 'published', 'closed'])->update(['status' => 'completed']);
        DB::table('pm_visits')->whereIn('status', ['revision_required'])->update(['status' => 'in_progress']);
        DB::table('pm_visits')->whereIn('status', ['draft'])->update(['status' => 'scheduled']);

        Schema::table('pm_visits', function (Blueprint $table) {
            foreach (['created_by', 'submitted_by', 'reviewed_by', 'approved_by', 'published_by', 'closed_by'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['title', 'reviewed_at', 'approved_at', 'published_at', 'closed_at', 'revision_note', 'revision_count', 'current_version', 'published_version']);
        });
        // Plan, contract, round and period stay nullable: rows made since may have none.
    }
};
