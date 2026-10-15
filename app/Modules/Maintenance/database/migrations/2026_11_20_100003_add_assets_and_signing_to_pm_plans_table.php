<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - sign_per_sheet: the customer signs every equipment sheet, not once per round.
 * - active: an inactive plan makes no new rounds.
 * - pm_plan_assets: the equipment a plan covers, each with an optional template of its own
 *   (template_code). A plan without rows covers every asset of its contract, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_plans', function (Blueprint $table) {
            $table->boolean('sign_per_sheet')->default(false)->after('assignee_id');
            $table->boolean('active')->default(true)->after('sign_per_sheet');
        });

        Schema::create('pm_plan_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('template_code', 40)->nullable();
            $table->timestamps();

            $table->unique(['pm_plan_id', 'asset_id']);
            $table->index(['tenant_id', 'asset_id']);
        });
        Rls::enable('pm_plan_assets');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_plan_assets');
        Schema::table('pm_plans', function (Blueprint $table) {
            $table->dropColumn(['sign_per_sheet', 'active']);
        });
    }
};
