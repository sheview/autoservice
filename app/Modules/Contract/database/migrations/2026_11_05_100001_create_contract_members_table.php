<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team of a project (MA contract): staff of the company who work on it. A grant with scope
 * "project" reaches the records of the projects whose team the user is on (DataScope). A
 * technician shared with several companies has an account in each, so is on the teams of each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contract_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::enable('contract_members');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_members');
    }
};
