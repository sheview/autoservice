<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;

/**
 * A code prefix for a new category when none is typed: the first English letters / digits of
 * its name (up to 4, "Notebook" -> NOTE), or CAT for a name without any (Thai), with a number
 * added until no other category of the company uses it (NOTE2, CAT3, ...).
 */
class GenerateCategoryPrefix
{
    public const MAX = 10;

    public function handle(string $name): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        $base = $letters !== '' ? substr($letters, 0, 4) : 'CAT';

        $taken = AssetCategory::withTrashed()->pluck('code_prefix')->map(fn (string $p) => strtoupper($p))->flip();
        $prefix = $base;
        for ($n = 2; $taken->has($prefix); $n++) {
            $prefix = substr($base, 0, self::MAX - strlen((string) $n)).$n;
        }

        return $prefix;
    }
}
