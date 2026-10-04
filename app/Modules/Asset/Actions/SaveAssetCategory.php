<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\SpecFields;

/**
 * Creates or updates an asset category and its spec fields.
 * Values of a removed field stay on the assets but are no longer shown or validated.
 */
class SaveAssetCategory
{
    public function __construct(private GenerateCategoryPrefix $generatePrefix) {}

    /**
     * @param  array{name: string, code_prefix?: string|null, service_line?: string|null, asset_type?: string, requires_serial?: bool,
     *     spec_fields?: list<array<string, mixed>>}  $data
     */
    public function handle(?AssetCategory $category, array $data): AssetCategory
    {
        $category ??= new AssetCategory;
        // None typed: a new category gets one made from its name; an existing one keeps its own.
        $prefix = filled($data['code_prefix'] ?? null) ? strtoupper(trim($data['code_prefix']))
            : ($category->exists ? $category->code_prefix : $this->generatePrefix->handle($data['name']));

        $category->fill([
            'name' => trim($data['name']),
            'code_prefix' => $prefix,
            'service_line' => $data['service_line'] ?? null,
            'asset_type' => $data['asset_type'] ?? $category->asset_type ?? AssetCategory::TYPE_HARDWARE,
            'requires_serial' => (bool) ($data['requires_serial'] ?? $category->requires_serial ?? false),
            'spec_fields' => SpecFields::clean($data['spec_fields'] ?? []),
        ])->save();

        return $category;
    }
}
