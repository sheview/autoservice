<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My work" calendar: when a technician is due on site for a ticket (optional), and each person's
 * own appointments, which only that person ever sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('appointment_at')->nullable()->after('resolve_due_at');
            $table->index(['tenant_id', 'assignee_id', 'appointment_at']);
        });

        Schema::create('personal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'user_id', 'starts_at']);
        });
        Rls::enable('personal_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_events');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assignee_id', 'appointment_at']);
            $table->dropColumn('appointment_at');
        });
    }
};
