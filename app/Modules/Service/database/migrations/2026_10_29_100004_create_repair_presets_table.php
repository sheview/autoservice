<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The symptoms and fixes a company sees most, offered as one-tap chips when a job is closed on a
 * phone (and symptoms on the customer's report form). Each company keeps its own list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('kind', 20); // symptom | solution
            $table->string('label', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'kind', 'sort']);
        });
        Rls::enable('repair_presets');
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_presets');
    }
};
