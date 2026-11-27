<?php

namespace App\Modules\Contract\Exports;

use App\Modules\Contract\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The customer list as an Excel sheet: what is on screen (same search and sort), every page.
 */
class CustomersExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    private const COLUMNS = ['code', 'name', 'short_name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'contracts_count', 'notes'];

    /**
     * @param  Builder<Customer>  $query
     */
    public function __construct(private Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return array_map(fn (string $column) => __("ui.customers.{$column}"), self::COLUMNS);
    }

    /**
     * @param  Customer  $customer
     */
    public function map($customer): array
    {
        return array_map(fn (string $column) => $customer->{$column}, self::COLUMNS);
    }
}
