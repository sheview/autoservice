<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Maintenance\Support\PmTemplateResolver;

/**
 * The template each asset would get on a work order made by hand (PmTemplateResolver, no plan), so the
 * page can show it before the work order is opened and point out equipment no template checks.
 */
class PmTemplatesForAssets
{
    public function __construct(private AssetDetails $assetDetails) {}

    /**
     * @param  list<int>  $assetIds
     * @return array<int, array{code: string, name: string}|null> keyed by asset id; null = no template
     */
    public function handle(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $templates = PmTemplateResolver::load();

        return array_map(function (array $asset) use ($templates) {
            $template = $templates->for($asset);

            return $template ? ['code' => $template->code, 'name' => $template->name] : null;
        }, $this->assetDetails->handle($assetIds));
    }
}
