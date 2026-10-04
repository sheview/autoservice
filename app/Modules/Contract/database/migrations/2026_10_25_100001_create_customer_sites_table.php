<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The places of a customer (head office, a branch, a data center), where its networks live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('customer_sites');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sites');
    }
};
