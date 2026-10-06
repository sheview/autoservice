<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Customers of the MA company (the tenant): the other party of a contract and the owner of assets.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('tax_id', 20)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Code is used in Excel import/export, so it is unique per tenant (ignoring case).
        LiveUnique::add('customers', ['tenant_id', 'code'], 'customers_tenant_code_unique');
        Rls::enable('customers');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
