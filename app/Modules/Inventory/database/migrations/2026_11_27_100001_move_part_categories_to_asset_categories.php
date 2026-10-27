<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts and assets share one list of categories: parts.part_category_id becomes
 * parts.asset_category_id. Each part category goes to the asset category of the same name in
 * its company, or to a new one (code prefix made as GenerateCategoryPrefix does, requires_serial
 * = its track_serial). part_categories stays as a record of the old list but is no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('asset_category_id')->nullable()->after('contract_id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('part_categories')->orderBy('id')->get() as $old) {
            $parts = DB::table('parts')->where('tenant_id', $old->tenant_id)->where('part_category_id', $old->id);
            if ($old->deleted_at !== null && ! $parts->exists()) {
                continue;
            }
            $id = DB::table('asset_categories')->where('tenant_id', $old->tenant_id)->whereNull('deleted_at')
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($old->name))])->value('id')
                ?? DB::table('asset_categories')->insertGetId([
                    'tenant_id' => $old->tenant_id,
                    'name' => trim($old->name),
                    'code_prefix' => $this->prefix($old->tenant_id, $old->name),
                    'asset_type' => 'hardware',
                    'requires_serial' => (bool) $old->track_serial,
                    'spec_fields' => '[]',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            $parts->update(['asset_category_id' => $id]);
        }

        Schema::table('parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->foreignId('part_category_id')->nullable()->after('contract_id')->constrained()->nullOnDelete();
        });

        // Back to the part category of the same name, made again when it is not there.
        $used = DB::table('parts')->whereNotNull('asset_category_id')->select('tenant_id', 'asset_category_id')->distinct()->get();
        foreach ($used as $row) {
            $category = DB::table('asset_categories')->where('tenant_id', $row->tenant_id)->where('id', $row->asset_category_id)->first();
            if ($category === null) {
                continue;
            }
            $id = DB::table('part_categories')->where('tenant_id', $row->tenant_id)->whereNull('deleted_at')
                ->whereRaw('lower(name) = ?', [mb_strtolower($category->name)])->value('id')
                ?? DB::table('part_categories')->insertGetId([
                    'tenant_id' => $row->tenant_id,
                    'name' => mb_substr($category->name, 0, 100),
                    'track_serial' => (bool) $category->requires_serial,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            DB::table('parts')->where('tenant_id', $row->tenant_id)->where('asset_category_id', $row->asset_category_id)->update(['part_category_id' => $id]);
        }

        Schema::table('parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_category_id');
        });
    }

    /** As GenerateCategoryPrefix, within the company of the category (no tenant context here). */
    private function prefix(int $tenantId, string $name): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        $base = $letters !== '' ? substr($letters, 0, 4) : 'CAT';
        $taken = DB::table('asset_categories')->where('tenant_id', $tenantId)->pluck('code_prefix')
            ->map(fn (string $p) => strtoupper($p))->flip();
        $prefix = $base;
        for ($n = 2; $taken->has($prefix); $n++) {
            $prefix = substr($base, 0, 10 - strlen((string) $n)).$n;
        }

        return $prefix;
    }
};
