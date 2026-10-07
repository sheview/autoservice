<?php

namespace App\Modules\Identity\Exports;

use App\Modules\Identity\Actions\ImportUsers;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The empty sheet for ImportUsers: one row of Thai headings.
 */
class UserImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return array_map(fn (string $column) => __("identity.imports.columns.{$column}"), ImportUsers::COLUMNS);
    }
}
