<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server rooms of customers that our staff ask to enter, with each room's own access rules.
 *
 * - server_rooms: a room at a customer (and one of its sites): whether entrants need an ID
 *   number, how its rules are accepted, periods nobody may enter (freeze).
 * - server_room_managers: our users who look after a room (may record entering / leaving).
 * - room_rule_versions: the customer's rules of the room, one row per version, never changed:
 *   a change is a new version, so a request keeps the version it accepted.
 * - room_approval_steps: who approves requests of the room, step by step. One step of our company
 *   for now; more steps and approvers on the customer's side fit without changing the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('ulid')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('customer_sites')->nullOnDelete();
            $table->string('name');
            $table->text('location')->nullable();
            // Entrants must give an ID card number (kept only then, masked, deleted after a while).
            $table->boolean('requires_id_number')->default(false);
            // No rules yet: block requests, or show the company's own terms with a warning.
            $table->string('missing_rules', 20)->default('block'); // block | company_terms
            // Accept the rules on every request, or once per version per person.
            $table->string('accept_mode', 20)->default('every_request'); // every_request | once_per_version
            $table->boolean('accept_on_enter')->default(false);
            // Each entrant accepts the rules through a link of their own (else the requester for the team).
            $table->boolean('entrants_accept_self')->default(false);
            // Nobody may enter: [{from: Y-m-d H:i, to: Y-m-d H:i, reason}]
            $table->json('freeze_periods')->default('[]');
            // Guards / caretakers at the site who get the link of an approved request: [{name, phone, email}]
            $table->json('guard_contacts')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id']);
        });
        LiveUnique::add('server_rooms', ['tenant_id', 'customer_id', 'name'], 'server_rooms_tenant_customer_name_unique');
        DB::statement("ALTER TABLE server_rooms ADD CONSTRAINT server_rooms_missing_rules_check CHECK (missing_rules IN ('block', 'company_terms'))");
        DB::statement("ALTER TABLE server_rooms ADD CONSTRAINT server_rooms_accept_mode_check CHECK (accept_mode IN ('every_request', 'once_per_version'))");
        Rls::enable('server_rooms');

        Schema::create('server_room_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('server_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['server_room_id', 'user_id']);
        });
        Rls::enable('server_room_managers');

        // Rows are only ever added: a change of the rules is the next version.
        Schema::create('room_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('server_room_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            // The rules in short, one line each (5-8), as the accept popup shows them.
            $table->json('summary');
            $table->date('effective_on');
            // Where the rules came from: who at the customer gave them, and when.
            $table->string('received_from')->nullable();
            $table->date('received_on')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamp('created_at');

            $table->unique(['server_room_id', 'version']);
        });
        Rls::enable('room_rule_versions');

        Schema::create('room_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('server_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // company = our users (room-access.approve); customer = through a link, later.
            $table->string('side', 20)->default('company');
            // A named approver; null = anyone holding room-access.approve (never the requester).
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The customer's approver (side customer): name and e-mail for the link.
            $table->string('approver_name')->nullable();
            $table->string('approver_email')->nullable();
            $table->timestamps();

            $table->unique(['server_room_id', 'position']);
        });
        Rls::enable('room_approval_steps');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_approval_steps');
        Schema::dropIfExists('room_rule_versions');
        Schema::dropIfExists('server_room_managers');
        Schema::dropIfExists('server_rooms');
    }
};
