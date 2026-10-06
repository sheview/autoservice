<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manuals of the company (how-tos, device manuals, procedures): a title, a category to group them,
 * a description, links (label + url) and attached files (media "attachments").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->json('links')->default('[]'); // [{label, url}]
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'category']);
        });
        Rls::enable('manuals');
    }

    public function down(): void
    {
        Schema::dropIfExists('manuals');
    }
};
