<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects what the register says about a device as found on site (a PM visit, Maintenance): who uses it,
 * its IP, where it is, its property number and its serial number. Only these columns change; the asset's
 * activity log keeps the change with who made it. Unknown ids (other tenant, deleted) give null.
 *
 * The serial number is the one shown (serial_number, the asset's first serial): that serial row is
 * changed in place, the asset's other serials stay. A serial is on one asset only (as in AssetRequest).
 */
class CorrectAssetDetails
{
    public const FIELDS = ['used_by', 'ip_address', 'location', 'property_no', 'serial_number'];

    /**
     * @param  array{used_by?: string|null, ip_address?: string|null, location?: string|null, property_no?: string|null, serial_number?: string|null}  $data  validated
     * @return array<string, string|null>|null the fields as saved
     */
    public function handle(int $assetId, array $data): ?array
    {
        $asset = Asset::find($assetId);
        if (! $asset) {
            return null;
        }

        $changes = array_map(fn ($value) => filled($value) ? trim((string) $value) : null, array_intersect_key($data, array_flip(self::FIELDS)));

        // The register needs a branch or a location (AssetRequest).
        if (array_key_exists('location', $changes) && $changes['location'] === null && ! $asset->branch_id) {
            throw ValidationException::withMessages(['location' => __('asset.assets.place_required')]);
        }

        DB::transaction(function () use ($asset, $changes) {
            if (array_key_exists('serial_number', $changes)) {
                $this->correctSerial($asset, $changes['serial_number']);
                unset($changes['serial_number']);
            }
            $asset->fill($changes)->save();
        });

        return $asset->only(self::FIELDS);
    }

    private function correctSerial(Asset $asset, ?string $serial): void
    {
        $old = $asset->serial_number;
        if ($serial === $old) {
            return;
        }

        $category = $asset->category;
        if ($serial === null && $category?->requires_serial && $asset->serials()->count() <= 1) {
            throw ValidationException::withMessages(['serial_number' => __('asset.assets.serial_required', ['category' => $category->name])]);
        }

        if ($serial !== null) {
            $taken = AssetSerial::query()->with('asset:id,asset_code')
                ->where(DB::raw('lower(serial_number)'), mb_strtolower($serial))
                ->where('asset_id', '!=', $asset->id)
                ->first();
            if ($taken) {
                throw ValidationException::withMessages(['serial_number' => __('asset.assets.serial_taken', [
                    'serial' => $serial, 'code' => $taken->asset?->asset_code ?? '-',
                ])]);
            }
        }

        $serials = $asset->serials()->orderBy('id')->get();
        $shown = $old === null ? null : $serials->first(fn (AssetSerial $row) => mb_strtolower($row->serial_number) === mb_strtolower($old));
        $same = $serial === null ? null : $serials->first(fn (AssetSerial $row) => $row !== $shown && mb_strtolower($row->serial_number) === mb_strtolower($serial));

        if ($serial === null || $same) {
            // Cleared, or already one of the asset's other serials: the shown one goes.
            $shown?->delete();
        } elseif ($shown) {
            $shown->update(['serial_number' => $serial]);
        } else {
            $asset->serials()->create(['serial_number' => $serial]);
        }

        // The first serial stays on the asset for lists, labels and tickets (SaveAsset).
        $count = $asset->serials()->count();
        $asset->serial_number = $asset->serials()->orderBy('id')->value('serial_number');
        if ($category?->requires_serial) {
            $asset->quantity = max($count, 1);
        }
    }
}
