<?php

use App\Modules\Tenancy\Support\LiveUnique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One live work order per round of a plan: the daily run (pm:generate-rounds) and a plan being saved
 * at the same moment cannot both make it. Deleted work orders (a plan re-scheduled) do not count; work
 * orders made by hand have no plan and are not concerned.
 */
return new class extends Migration
{
    public function up(): void
    {
        LiveUnique::add('pm_visits', ['pm_plan_id', 'round'], 'pm_visits_plan_round_unique');
    }

    public function down(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->dropUnique('pm_visits_plan_round_unique');
        });
        // "alive" stays: other unique indexes may come to use it.
    }
};
