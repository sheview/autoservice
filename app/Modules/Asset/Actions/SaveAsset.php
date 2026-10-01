<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetSerial;
use App\Modules\Asset\Support\SpecFields;
use App\Modules\Platform\Actions\SendAlert;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an asset of the current tenant and its serial numbers.
 * An empty asset_code gets the next code of the category's prefix.
 */
class SaveAsset
{
    public function __construct(private GenerateAssetCode $generateCode) {}

    /**
     * @param  array{category_id: int, name: string, asset_code?: string|null, branch_id?: int|null,
     *     brand?: string|null, model?: string|null, subtype?: string|null, serial_number?: string|null,
     *     quantity?: int, unit?: string|null, status: string, location?: string|null, purchased_at?: string|null,
     *     purchase_price?: int|null, warranty_expires_at?: string|null, specs?: array<string, mixed>, notes?: string|null}  $data
     *     validated data; purchase_price in satang
     * @param  list<string>|null  $serials  every serial number of the asset (validated unique); null keeps
     *                                      them, unless $data has serial_number, which then is the only one
     */
    public function handle(?Asset $asset, array $data, ?array $serials = null): Asset
    {
        return DB::transaction(function () use ($asset, $data, $serials) {
            $asset ??= new Asset;
            $category = AssetCategory::findOrFail($data['category_id']);

            $data['specs'] = SpecFields::normalize($category->spec_fields, $data['specs'] ?? []);

            if (blank($data['asset_code'] ?? null)) {
                $data['asset_code'] = $asset->exists ? $asset->asset_code : $this->generateCode->handle($category->code_prefix);
            }

            if ($serials === null && array_key_exists('serial_number', $data)) {
                $serials = filled($data['serial_number']) ? [trim($data['serial_number'])] : [];
            }
            if ($serials !== null) {
                // The first serial stays on the asset for lists, labels and tickets.
                $data['serial_number'] = $serials[0] ?? null;
                if ($category->requires_serial) {
                    $data['quantity'] = max(count($serials), 1);
                }
            }

            $isNew = ! $asset->exists;
            $asset->fill($data)->save();

            // Sent for repair (a change of an existing asset; not a new one or an import of new ones).
            if (! $isNew && $asset->wasChanged('status') && $asset->status === Asset::STATUS_IN_REPAIR) {
                app(SendAlert::class)->handle('asset_in_repair', [
                    'code' => $asset->asset_code,
                    'name' => $asset->name,
                    'location' => $asset->location,
                ], route('asset.assets.show', $asset));
            }

            if ($serials !== null) {
                $this->syncSerials($asset, $serials);
            }

            return $asset;
        });
    }

    /**
     * @param  list<string>  $serials
     */
    private function syncSerials(Asset $asset, array $serials): void
    {
        $wanted = collect($serials)->keyBy(fn (string $serial) => mb_strtolower($serial));
        $current = $asset->serials()->get()->keyBy(fn (AssetSerial $serial) => mb_strtolower($serial->serial_number));

        // Removed first, so a serial can move between rows of the form without hitting the unique index.
        $current->diffKeys($wanted)->each(fn (AssetSerial $serial) => $serial->delete());

        foreach ($wanted as $key => $serial) {
            $existing = $current->get($key);
            if ($existing === null) {
                $asset->serials()->create(['serial_number' => $serial]);
            } elseif ($existing->serial_number !== $serial) {
                $existing->update(['serial_number' => $serial]); // only the letter case changed
            }
        }
    }
}
