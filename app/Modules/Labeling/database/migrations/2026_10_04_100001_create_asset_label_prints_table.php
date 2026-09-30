<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log of printed asset labels: one row per asset per print (a log, so no soft delete).
        Schema::create('asset_label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template', 20);
            $table->timestamp('printed_at');

            $table->index(['tenant_id', 'asset_id', 'printed_at']);
        });
        Rls::enable('asset_label_prints');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_label_prints');
    }
};
