<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A short number for each company (001, 002, ...) that customers see in ticket numbers
 * (TK001-2569-00001) and can type to find their job. Given once, never changed, never given again:
 * company_codes keeps every code ever issued, even of a company deleted since.
 * Platform data (no tenant_id, no row level security), like "tenants".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('code', 10)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('company_code', 10)->nullable()->unique()->after('subdomain');
        });

        // The companies there are now, in the order they were made (not the platform: it is no company).
        $n = 0;
        foreach (DB::table('tenants')->where('is_platform', false)->orderBy('id')->pluck('id') as $id) {
            $n++;
            $code = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            DB::table('company_codes')->insert(['number' => $n, 'code' => $code, 'tenant_id' => $id, 'created_at' => now()]);
            DB::table('tenants')->where('id', $id)->update(['company_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['company_code']);
            $table->dropColumn('company_code');
        });
        Schema::dropIfExists('company_codes');
    }
};
