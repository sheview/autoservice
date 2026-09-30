<?php

namespace App\Modules\Inventory\Exports;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Support\PartSheet;
use App\Modules\Platform\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Parts as an Excel sheet in the PartSheet layout (also used, with no rows, as the import template).
 */
class PartsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  Builder<Part>  $query
     */
    public function __construct(private Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return PartSheet::headings();
    }

    /**
     * @param  Part  $part
     */
    public function map($part): array
    {
        return [
            $part->code,
            $part->name,
            $part->brand,
            $part->part_number,
            $part->unit,
            $part->min_qty,
            Money::toBaht($part->unit_cost),
            $part->qty_on_hand,
            __('inventory.statuses.'.($part->is_active ? 'active' : 'inactive')),
            $part->notes,
        ];
    }
}
