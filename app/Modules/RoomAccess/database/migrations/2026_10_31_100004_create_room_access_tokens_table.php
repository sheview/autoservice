<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links that work without signing in, each for one request only, until it expires or is revoked:
 *
 *   permit    the approved request as shown at the guard's counter (its QR on the permit)
 *   guard     the guard / caretaker records entering and leaving (step 5)
 *   approver  an approver of the customer's side decides their step (later)
 *   entrant   an entrant accepts the rules for themselves (later)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->constrained('room_access_requests')->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('token', 64)->unique();
            // For whom (an entrant, a guard of the room's list), when it is for one person.
            $table->foreignId('person_id')->nullable()->constrained('room_access_people')->nullOnDelete();
            $table->string('holder_name')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'request_id', 'purpose']);
        });
        DB::statement("ALTER TABLE room_access_tokens ADD CONSTRAINT room_access_tokens_purpose_check CHECK (purpose IN ('permit', 'guard', 'approver', 'entrant'))");
        Rls::enable('room_access_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_access_tokens');
    }
};
