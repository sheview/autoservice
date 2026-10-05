<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each decision on a request, step by step of its room's approval (room_approval_steps): approved,
 * turned down (with why) or sent back for more information (with what). A request is approved
 * once every step has approved, in order; one step at a time, none skipped. Approvers on the
 * customer's side (through a link) fit here too, with their name instead of a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_access_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->string('side', 20)->default('company');
            $table->string('decision', 20); // approved | rejected | asked
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            // Which submission it answers: a request sent back and sent again starts over.
            $table->unsignedSmallInteger('round')->default(1);
            $table->timestamp('decided_at');

            $table->index(['tenant_id', 'request_id']);
        });
        Rls::enable('room_access_approvals');

        Schema::table('room_access_requests', function (Blueprint $table) {
            // Bumped each time the request is sent (again) for approval.
            $table->unsignedSmallInteger('round')->default(0)->after('status');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_access_requests', function (Blueprint $table) {
            $table->dropColumn(['round', 'approved_at']);
        });
        Schema::dropIfExists('room_access_approvals');
    }
};
