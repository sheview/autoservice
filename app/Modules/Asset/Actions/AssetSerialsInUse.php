<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetSerial;

/**
 * Which of these serial numbers an asset of the tenant already carries (ignoring case), with the
 * asset's code: the Inventory module warns about them when parts are received.
 */
class AssetSerialsInUse
{
    /**
     * @param  list<string>  $serials
     * @return array<string, string> lower-case serial => asset code
     */
    public function handle(array $serials): array
    {
        if ($serials === []) {
            return [];
        }

        return AssetSerial::query()
            ->with('asset:id,asset_code')
            ->whereIn(\DB::raw('lower(serial_number)'), array_map('mb_strtolower', $serials))
            ->get()
            ->mapWithKeys(fn (AssetSerial $serial) => [mb_strtolower($serial->serial_number) => $serial->asset?->asset_code ?? '-'])
            ->all();
    }
}
