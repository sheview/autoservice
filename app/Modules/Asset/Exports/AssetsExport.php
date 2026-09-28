<?php

namespace App\Modules\Asset\Exports;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Support\AssetSheet;
use App\Modules\Platform\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Assets as an Excel sheet in the AssetSheet layout (also used, with no rows, as the import template).
 */
class AssetsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  Builder<Asset>  $query
     * @param  array<string, string>  $specFields  key => label, one column each
     * @param  array<int, string>  $customerCodes  customer id => code
     */
    public function __construct(
        private Builder $query,
        private array $specFields,
        private array $customerCodes = [],
    ) {}

    public function query(): Builder
    {
        return $this->query->with(['category:id,name', 'branch:id,code,name']);
    }

    public function headings(): array
    {
        return AssetSheet::headings($this->specFields);
    }

    /**
     * @param  Asset  $asset
     */
    public function map($asset): array
    {
        $row = [
            $asset->asset_code,
            $asset->name,
            $asset->category?->name,
            $asset->branch?->code,
            $this->customerCodes[$asset->customer_id] ?? null,
            $asset->brand,
            $asset->model,
            $asset->serial_number,
            __("asset.statuses.{$asset->status}"),
            $asset->location,
            $asset->purchased_at?->toDateString(),
            Money::toBaht($asset->purchase_price),
            $asset->warranty_expires_at?->toDateString(),
            $asset->notes,
        ];

        foreach (array_keys($this->specFields) as $key) {
            $row[] = $asset->specs[$key] ?? null;
        }

        return $row;
    }
}
