<?php

use App\Modules\Tenancy\Support\LiveUnique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A satisfaction survey may be of a PM work order too (answered when the customer signs it), not
 * only of a ticket: ticket_id becomes optional, pm_visit_id is the work order (one live survey each).
 * Surveys already there keep their ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_surveys', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->change();
            $table->foreignId('pm_visit_id')->nullable()->after('ticket_id')->constrained()->cascadeOnDelete();
        });

        LiveUnique::add('ticket_surveys', ['tenant_id', 'pm_visit_id'], 'ticket_surveys_tenant_pm_visit_unique');
    }

    public function down(): void
    {
        Schema::table('ticket_surveys', function (Blueprint $table) {
            $table->dropUnique('ticket_surveys_tenant_pm_visit_unique');
            $table->dropConstrainedForeignId('pm_visit_id');
        });
        // ticket_id stays optional: surveys of work orders may have been taken since.
    }
};
