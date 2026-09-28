<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Days off of the MA company: SLA time of 8x5 / 12x6 contracts does not run on these days.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
        });

        Rls::enable('holidays');
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
