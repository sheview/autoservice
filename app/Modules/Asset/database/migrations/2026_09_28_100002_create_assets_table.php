<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('asset_categories')->restrictOnDelete();
            // Unique per tenant including deleted assets: a printed label never points to another asset.
            $table->string('asset_code', 50);
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('status', 20);
            $table->string('location')->nullable();
            $table->date('purchased_at')->nullable();
            $table->bigInteger('purchase_price')->nullable(); // satang
            $table->date('warranty_expires_at')->nullable();
            // Values of the category's spec_fields, keyed by field key.
            $table->json('specs')->default('{}');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'asset_code']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'warranty_expires_at']);
        });

        Rls::enable('assets');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
