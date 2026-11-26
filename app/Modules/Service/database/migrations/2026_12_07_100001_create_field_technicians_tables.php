<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outside technicians around the country who signed up through the company's link, so a job far
 * away can go straight to someone nearby instead of phoning round.
 *
 * - field_technicians: who they are and how to reach them; "pending" until staff approve them.
 * - field_technician_tags: the provinces they work in and the kinds of work they take, one row
 *   each, to be searched on (kind = province | skill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 50);
            // The digits of the phone only, so "081-234-5678" and "0812345678" are the same person.
            $table->string('phone_key', 20);
            $table->string('line_id', 100)->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reviewed_by_name')->nullable();
            // When they agreed to their details being kept and used to contact them (PDPA).
            $table->dateTime('consented_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });
        LiveUnique::add('field_technicians', ['tenant_id', 'phone_key'], 'field_technicians_tenant_phone_unique');
        DB::statement("ALTER TABLE field_technicians ADD CONSTRAINT field_technicians_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
        Rls::enable('field_technicians');

        Schema::create('field_technician_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('field_technician_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // province | skill
            $table->string('value', 100);
            $table->timestamps();

            $table->unique(['field_technician_id', 'kind', 'value'], 'field_technician_tags_unique');
            $table->index(['tenant_id', 'kind', 'value'], 'field_technician_tags_search');
        });
        DB::statement("ALTER TABLE field_technician_tags ADD CONSTRAINT field_technician_tags_kind_check CHECK (kind IN ('province', 'skill'))");
        Rls::enable('field_technician_tags');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_technician_tags');
        Schema::dropIfExists('field_technicians');
    }
};
