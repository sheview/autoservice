<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requests to enter a server room:
 *
 *   draft → pending → approved → inside → exited   (rejected, cancelled, overdue)
 *
 * - room_access_requests: the room, when, why, the ticket / MA contract it is for.
 * - room_access_people: who goes in; an ID card number only when the room asks (encrypted, masked
 *   when shown, deleted after the company's retention).
 * - room_access_items: equipment taken in or out.
 * - room_rule_acceptances: evidence that the room's rules were accepted: by whom, when, from where,
 *   which version, and the very text shown then.
 * - room_access_events: every change of status, who made it and when.
 * - room_visitors: people a requester entered before, to pick again (never their ID numbers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_access_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('prefix', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'prefix']);
        });
        Rls::enable('room_access_sequences');

        Schema::create('room_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('request_no', 30);
            $table->string('status', 20)->default('draft');
            $table->foreignId('server_room_id')->constrained()->restrictOnDelete();
            // The room's customer, kept on the request for lists and scopes.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name')->nullable();
            $table->timestamp('planned_start');
            $table->timestamp('planned_end');
            $table->text('purpose');
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            // The rules version accepted when it was sent (null: the room had none, company terms only).
            $table->foreignId('rule_version_id')->nullable()->constrained('room_rule_versions')->nullOnDelete();
            // What the approver asked for (status back to draft), or why it was turned down.
            $table->text('decision_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->text('work_summary')->nullable();
            $table->timestamp('items_confirmed_at')->nullable();
            // When the entrants' ID numbers were deleted (retention).
            $table->timestamp('id_numbers_purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'request_no']);
            $table->index(['tenant_id', 'status', 'planned_start']);
            $table->index(['tenant_id', 'requester_id']);
            $table->index(['tenant_id', 'server_room_id', 'planned_start']);
        });
        DB::statement("ALTER TABLE room_access_requests ADD CONSTRAINT room_access_requests_status_check CHECK (status IN ('draft', 'pending', 'approved', 'inside', 'exited', 'rejected', 'cancelled', 'overdue'))");
        DB::statement('ALTER TABLE room_access_requests ADD CONSTRAINT room_access_requests_times_check CHECK (planned_end > planned_start)');
        Rls::enable('room_access_requests');

        Schema::create('room_access_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('id_number')->nullable(); // encrypted; only when the room asks
            $table->timestamps();
            $table->index(['tenant_id', 'request_id']);
        });
        Rls::enable('room_access_people');

        Schema::create('room_access_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('serial_number', 100)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            // in = taken in (and back out), out = taken out of the room
            $table->string('direction', 10)->default('in');
            $table->timestamps();
            $table->index(['tenant_id', 'request_id']);
        });
        Rls::enable('room_access_items');

        // Rows are only ever added.
        Schema::create('room_rule_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('server_room_id')->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('room_access_requests')->nullOnDelete();
            $table->foreignId('rule_version_id')->nullable()->constrained('room_rule_versions')->nullOnDelete();
            $table->unsignedInteger('version')->nullable();
            // submit | enter | self (an entrant through their own link)
            $table->string('context', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('room_access_people')->nullOnDelete();
            $table->string('accepted_by_name');
            // Accepted for the whole team (the requester answers for telling them).
            $table->boolean('on_behalf_of_team')->default(false);
            // The text shown then: customer and room, the rules' lines, the company's terms.
            $table->jsonb('snapshot');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');

            $table->index(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'user_id', 'rule_version_id']);
        });
        Rls::enable('room_rule_acceptances');

        Schema::create('room_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'request_id']);
        });
        Rls::enable('room_access_events');

        Schema::create('room_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamp('last_used_at');
            $table->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX room_visitors_owner_person_unique ON room_visitors (tenant_id, owner_id, lower(name), lower(coalesce(company, '')))");
        Rls::enable('room_visitors');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_visitors');
        Schema::dropIfExists('room_access_events');
        Schema::dropIfExists('room_rule_acceptances');
        Schema::dropIfExists('room_access_items');
        Schema::dropIfExists('room_access_people');
        Schema::dropIfExists('room_access_requests');
        Schema::dropIfExists('room_access_sequences');
    }
};
