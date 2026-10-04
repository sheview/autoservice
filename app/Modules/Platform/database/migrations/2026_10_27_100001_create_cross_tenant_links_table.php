<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents of two companies that belong together, e.g. a ticket of A and the issue request it
 * made in B through a share. Platform data like tenant_shares (no tenant_id, no row level
 * security): only read and written by App\Modules\Platform\CrossTenant, which always names the
 * company it works for. The labels keep what each side may show of the other without reading it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cross_tenant_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('source_type', 30); // ticket
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_label')->nullable(); // e.g. the ticket number
            $table->foreignId('target_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('target_type', 30); // checkout_request
            $table->unsignedBigInteger('target_id');
            $table->string('target_label')->nullable(); // e.g. the request number
            $table->unsignedBigInteger('created_by_id')->nullable(); // a user of the source company (or central staff)
            $table->string('created_by_name')->nullable();
            $table->timestamps();
            $table->index(['source_tenant_id', 'source_type', 'source_id']);
            $table->index(['target_tenant_id', 'target_type', 'target_id']);
            $table->index(['source_tenant_id', 'created_by_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_tenant_links');
    }
};
