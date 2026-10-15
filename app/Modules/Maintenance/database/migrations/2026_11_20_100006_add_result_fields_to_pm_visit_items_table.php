<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - The result of a piece of equipment is named as on the sheets:
 *   ok → passed, issue → failed, skipped → not_inspected (pending stays).
 * - not_inspected_reason: user_absent | sent_for_repair | not_found | other (details in note).
 * - template_code / template_version: the template the checklist copy came from.
 * - instrument_id: the measuring instrument used.
 */
return new class extends Migration
{
    private const RESULTS = ['ok' => 'passed', 'issue' => 'failed', 'skipped' => 'not_inspected'];

    public function up(): void
    {
        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->string('result', 20)->change();
            $table->string('not_inspected_reason', 30)->nullable()->after('result');
            $table->string('template_code', 40)->nullable()->after('pm_checklist_id');
            $table->unsignedSmallInteger('template_version')->nullable()->after('template_code');
            $table->foreignId('instrument_id')->nullable()->after('remarks')->constrained('pm_instruments')->nullOnDelete();
        });

        // Every tenant on purpose: a change of the result values, not of anyone's data.
        foreach (self::RESULTS as $old => $new) {
            DB::table('pm_visit_items')->where('result', $old)->update(['result' => $new]);
        }

        // Within each tenant: the checklist an item came from belongs to the same tenant.
        DB::table('pm_visit_items')
            ->join('pm_checklists', function ($join) {
                $join->on('pm_checklists.id', '=', 'pm_visit_items.pm_checklist_id')
                    ->on('pm_checklists.tenant_id', '=', 'pm_visit_items.tenant_id');
            })
            ->update([
                'pm_visit_items.template_code' => DB::raw('pm_checklists.code'),
                'pm_visit_items.template_version' => DB::raw('pm_checklists.version'),
            ]);
    }

    public function down(): void
    {
        foreach (self::RESULTS as $old => $new) {
            DB::table('pm_visit_items')->where('result', $new)->update(['result' => $old]);
        }

        Schema::table('pm_visit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instrument_id');
            $table->dropColumn(['not_inspected_reason', 'template_code', 'template_version']);
            $table->string('result', 10)->change();
        });
    }
};
