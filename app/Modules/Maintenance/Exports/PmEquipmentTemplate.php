<?php

namespace App\Modules\Maintenance\Exports;

use App\Modules\Asset\Support\AssetSheet;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The Excel template of a PM plan's equipment import: the asset columns plus "แบบฟอร์ม PM", with
 * sample rows (a PC, its monitor as a part, a UPS), and a second sheet listing the company's forms.
 */
class PmEquipmentTemplate implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, string>  $categories  code prefix => the company's category name (for the samples)
     * @param  list<array{code: string, name: string, layout: string}>  $forms  published templates
     */
    public function __construct(private array $categories, private array $forms) {}

    public function sheets(): array
    {
        $headings = [...AssetSheet::headings([]), __('maintenance.plans.form_column')];
        $sample = fn (array $cells, ?string $form) => [...array_map(fn (string $column) => $cells[$column] ?? null, AssetSheet::COLUMNS), $form];
        $category = fn (string $prefix, string $fallback) => $this->categories[$prefix] ?? $fallback;
        $codes = array_column($this->forms, 'code');
        $form = fn (string $code) => in_array($code, $codes, true) ? $code : null;

        $rows = [
            $sample(['name' => 'PC ฝ่ายบัญชี 1', 'category' => $category('PC', 'คอมพิวเตอร์ตั้งโต๊ะ'), 'brand' => 'Dell', 'model' => 'OptiPlex 7010',
                'serial_number' => 'SN-PC-0001', 'property_no' => 'ครุภัณฑ์-0001', 'location' => 'อาคาร A', 'floor' => '2', 'room' => 'ฝ่ายบัญชี'], $form('PC-SET')),
            $sample(['name' => 'จอ ฝ่ายบัญชี 1', 'category' => $category('MON', 'จอ Monitor'), 'brand' => 'Dell', 'model' => 'P2422H',
                'serial_number' => 'SN-MON-0001', 'location' => 'อาคาร A', 'floor' => '2', 'room' => 'ฝ่ายบัญชี', 'parent' => 'SN-PC-0001'], $form('PC-SET')),
            $sample(['name' => 'UPS-01', 'category' => $category('UPS', 'UPS'), 'brand' => 'Eaton', 'model' => '93PM 60kVA',
                'serial_number' => 'SN-UPS-0001', 'location' => 'อาคาร Data Center', 'floor' => '1', 'room' => 'ห้อง UPS', 'ip_address' => '10.0.0.21'], $form('DC-UPS')),
        ];

        return [
            new class($headings, $rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
            {
                public function __construct(private array $headings, private array $rows) {}

                public function array(): array
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return $this->headings;
                }

                public function title(): string
                {
                    return __('maintenance.plans.import_sheet');
                }
            },
            new class($this->forms) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
            {
                public function __construct(private array $forms) {}

                public function array(): array
                {
                    return array_map(fn (array $form) => [$form['code'], $form['name'], __("ui.pm_checklists.layouts.{$form['layout']}")], $this->forms);
                }

                public function headings(): array
                {
                    return [__('maintenance.plans.form_column'), __('maintenance.plans.form_name'), __('maintenance.plans.form_layout')];
                }

                public function title(): string
                {
                    return __('maintenance.plans.forms_sheet');
                }
            },
        ];
    }
}
