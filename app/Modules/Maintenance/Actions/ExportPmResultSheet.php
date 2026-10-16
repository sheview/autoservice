<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmNumber;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A work order's results as one Excel sheet in the company's own layout: its name and logo, the
 * round and month (Buddhist year, as the printed documents), a row per piece of equipment (code,
 * brand/model, serial, place, ปกติ / ผิดปกติ / ไม่ได้ตรวจ, remark) and the three signatures with
 * their dates. The same file comes back filled in (ImportPmResultSheet): column J holds each
 * row's item, hidden.
 */
class ExportPmResultSheet
{
    /** The first row of equipment, and the columns of the table. */
    public const FIRST_ROW = 7;

    public const COL_PASS = 'F';

    public const COL_FAIL = 'G';

    public const COL_SKIP = 'H';

    public const COL_REMARK = 'I';

    public const COL_ITEM = 'J';

    public const MARK = '✓';

    public function __construct(
        private TenantContext $context,
        private AssetDetails $assetDetails,
        private ListCustomers $listCustomers,
    ) {}

    /**
     * @return array{path: string, file: string} a temporary .xlsx and the name to download it as
     */
    public function handle(PmVisit $visit): array
    {
        $items = $visit->items()->orderBy('id')->get();
        $assets = $this->assetDetails->handle($items->pluck('asset_id')->all());
        $customer = collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id)['name'] ?? null;
        $tenant = $this->context->tenant();
        $number = PmNumber::shown($visit->visit_no, $visit->tenant_id);
        $month = $visit->due_on->locale('th');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('PM');
        $book->getDefaultStyle()->getFont()->setName('TH Sarabun New')->setSize(14);

        // Heading: the company, what this is, for whom.
        $sheet->setCellValue('A1', $tenant?->name);
        $sheet->setCellValue('A2', __('maintenance.result_sheet.title', [
            'round' => $visit->round ?? '-', 'month' => $month->translatedFormat('F'), 'year' => $month->year + 543,
        ]));
        $sheet->setCellValue('A3', __('maintenance.result_sheet.subtitle', ['customer' => $customer ?? '-', 'no' => $number, 'title' => $visit->displayTitle() ?? '-']));
        foreach (['A1' => 18, 'A2' => 16, 'A3' => 14] as $cell => $size) {
            $sheet->mergeCells($cell.':I'.substr($cell, 1));
            $sheet->getStyle($cell)->getFont()->setBold($cell !== 'A3')->setSize($size);
        }
        $sheet->getStyle('A2')->getFont()->getColor()->setRGB('2A2170');
        $this->logo($sheet, $tenant);

        // The table's header: two rows, "ผลการตรวจสอบ" over Pass / Fail / ไม่ได้ตรวจ.
        $head = self::FIRST_ROW - 2;
        foreach (['A' => 'no', 'B' => 'code', 'C' => 'model', 'D' => 'serial', 'E' => 'place', 'I' => 'remark'] as $col => $key) {
            $sheet->setCellValue("{$col}{$head}", __("maintenance.result_sheet.columns.{$key}"));
            $sheet->mergeCells("{$col}{$head}:{$col}".($head + 1));
        }
        $sheet->setCellValue("F{$head}", __('maintenance.result_sheet.columns.result'));
        $sheet->mergeCells("F{$head}:H{$head}");
        $sheet->setCellValue('F'.($head + 1), __('maintenance.result_sheet.columns.passed'));
        $sheet->setCellValue('G'.($head + 1), __('maintenance.result_sheet.columns.failed'));
        $sheet->setCellValue('H'.($head + 1), __('maintenance.result_sheet.columns.skipped'));
        $sheet->setCellValue(self::COL_ITEM.($head + 1), 'ref');
        $headRange = "A{$head}:I".($head + 1);
        $sheet->getStyle($headRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2F94');

        // A row per piece of equipment, as recorded so far.
        $row = self::FIRST_ROW;
        foreach ($items as $i => $item) {
            $asset = $assets[$item->asset_id] ?? [];
            $place = implode(' · ', array_filter([
                $asset['location'] ?? null,
                filled($asset['floor'] ?? null) ? __('maintenance.result_sheet.floor', ['floor' => $asset['floor']]) : null,
                $asset['room'] ?? null,
            ]));
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $asset['asset_code'] ?? null);
            $sheet->setCellValue("C{$row}", trim(($asset['brand'] ?? '').' '.($asset['model'] ?? '')) ?: ($asset['name'] ?? null));
            $sheet->setCellValueExplicit("D{$row}", (string) ($asset['serial_number'] ?? ''), 's');
            $sheet->setCellValue("E{$row}", $place ?: null);
            $mark = [PmVisitItem::RESULT_PASSED => self::COL_PASS, PmVisitItem::RESULT_FAILED => self::COL_FAIL, PmVisitItem::RESULT_NOT_INSPECTED => self::COL_SKIP][$item->result] ?? null;
            if ($mark) {
                $sheet->setCellValue("{$mark}{$row}", self::MARK);
            }
            // Not inspected: why (in words), then the note.
            $sheet->setCellValue(self::COL_REMARK.$row, $item->result === PmVisitItem::RESULT_NOT_INSPECTED
                ? implode(' · ', array_filter([$item->not_inspected_reason ? __("ui.pm_visits.not_inspected_reasons.{$item->not_inspected_reason}") : null, $item->note]))
                : $item->note);
            $sheet->setCellValue(self::COL_ITEM.$row, $item->id);
            $row++;
        }
        $last = max($row - 1, self::FIRST_ROW);

        $sheet->getStyle("A{$head}:I{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9CA3AF');
        $sheet->getStyle("A{$head}:I".($head + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A'.self::FIRST_ROW.":A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D'.self::FIRST_ROW.":H{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.self::FIRST_ROW.":I{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        $this->signatures($sheet, $visit, $last + 2);

        foreach (['A' => 7, 'B' => 14, 'C' => 30, 'D' => 20, 'E' => 30, 'F' => 10, 'G' => 10, 'H' => 11, 'I' => 28] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        // The item of each row, for the import: hidden, not to be changed.
        $sheet->getColumnDimension(self::COL_ITEM)->setVisible(false);
        $sheet->freezePane('A'.self::FIRST_ROW);
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($head, $head + 1);

        $path = tempnam(sys_get_temp_dir(), 'pm-results');
        (new Xlsx($book))->save($path);

        return ['path' => $path, 'file' => preg_replace('/[^A-Za-z0-9_.-]+/', '-', "PM-results-{$number}").'.xlsx'];
    }

    /** The three who sign (technician, customer, approver): their name and date once they have signed. */
    private function signatures(Worksheet $sheet, PmVisit $visit, int $row): void
    {
        $signed = $visit->signatures()->get()->keyBy('role');
        // Columns of each block: A-C, D-E, F-I.
        $blocks = [
            'A' => ['C', PmSignature::ROLE_ENGINEER, 'engineer'],
            'D' => ['E', PmSignature::ROLE_CUSTOMER, 'customer'],
            'F' => ['I', PmSignature::ROLE_APPROVER, 'approver'],
        ];
        foreach ($blocks as $col => [$end, $role, $label]) {
            $signature = $signed[$role] ?? null;
            $lines = [
                __('maintenance.result_sheet.sign_line'),
                '( '.($signature?->name ?? str_repeat('.', 30)).' )',
                __("document.pm_sheet.{$label}"),
                __('maintenance.result_sheet.sign_date', [
                    'date' => $signature?->signed_at ? $signature->signed_at->timezone(config('app.timezone'))->format('d/m/').($signature->signed_at->year + 543) : '......../......../........',
                ]),
            ];
            foreach ($lines as $i => $text) {
                $cell = $col.($row + $i);
                $sheet->setCellValue($cell, $text);
                $sheet->mergeCells("{$cell}:{$end}".($row + $i));
                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $sheet->getStyle($col.($row + 2))->getFont()->setBold(true);
        }
    }

    private function logo(Worksheet $sheet, $tenant): void
    {
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);
        if ($logo === null) {
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($file, stream_get_contents($logo->stream()));

        $drawing = new Drawing;
        $drawing->setPath($file, false);
        $drawing->setHeight(60);
        $drawing->setCoordinates('H1');
        $drawing->setWorksheet($sheet);
    }
}
