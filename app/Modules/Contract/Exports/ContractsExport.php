<?php

namespace App\Modules\Contract\Exports;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractSheet;
use App\Modules\Platform\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Contracts as an Excel sheet in the ContractSheet layout (also used, with no rows, as the import template).
 */
class ContractsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  Builder<Contract>  $query
     */
    public function __construct(private Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with(['customer:id,code', 'slas']);
    }

    public function headings(): array
    {
        return ContractSheet::headings();
    }

    /**
     * @param  Contract  $contract
     */
    public function map($contract): array
    {
        $row = [
            $contract->contract_no,
            $contract->customer?->code,
            $contract->title,
            __("contract.statuses.{$contract->status}"),
            $contract->starts_on->toDateString(),
            $contract->ends_on->toDateString(),
            Money::toBaht($contract->value),
            $contract->service_window,
            $contract->pm_interval_months,
            $contract->notify_days_before,
            $contract->notes,
        ];

        foreach (Contract::PRIORITIES as $priority) {
            $sla = $contract->slas->firstWhere('priority', $priority);
            $row[] = $sla ? $sla->response_minutes / 60 : null;
            $row[] = $sla ? $sla->resolve_minutes / 60 : null;
        }

        return $row;
    }
}
