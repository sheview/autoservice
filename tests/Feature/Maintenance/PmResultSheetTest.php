<?php

use App\Modules\Maintenance\Actions\ExportPmResultSheet;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * A work order's results as Excel in the company's layout, filled in and brought back.
 */

beforeEach(function () {
    $this->travelTo('2026-09-10 10:00');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'Switch']);
    $this->a = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'SW-1', 'brand' => 'Cisco', 'model' => 'C2960X', 'serial_number' => 'FCW2041A1G8', 'location' => 'Server Room', 'floor' => '2']);
    $this->b = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'SW-2', 'brand' => 'Cisco', 'model' => 'C2960X', 'serial_number' => 'FCW2041A1JK']);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $this->a->id]);
    $contract->contractAssets()->create(['asset_id' => $this->b->id]);
    createPmTemplate(['name' => 'Switch PM', 'asset_category_id' => $category->id, 'items' => [
        ['key' => 'fan', 'label' => 'พัดลม', 'type' => 'pass_fail', 'required' => true],
        ['key' => 'clean', 'label' => 'ทำความสะอาด', 'type' => 'pass_fail'],
    ]]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";
    $this->actingAs($this->tech)->post("{$this->url}/start")->assertSessionHasNoErrors();

    // Downloads the sheet and opens it.
    $this->download = function () {
        $response = $this->actingAs($this->tech)->get("{$this->url}/results.xlsx")->assertOk();

        return IOFactory::load($response->getFile()->getPathname());
    };
    // Sends a sheet back.
    $this->upload = function ($book) {
        $path = tempnam(sys_get_temp_dir(), 'pmr').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->actingAs($this->tech)->post("{$this->url}/results", ['file' => new UploadedFile($path, 'results.xlsx', null, null, true)]);
    };
    $this->itemOf = fn ($asset) => PmVisitItem::where('pm_visit_id', $this->visit->id)->where('asset_id', $asset->id)->sole();
});

it('exports a row per piece of equipment, with the round, the month and the signatures', function () {
    $sheet = ($this->download)()->getActiveSheet();
    $row = ExportPmResultSheet::FIRST_ROW;

    expect($sheet->getCell('F'.($row - 1))->getValue())->toBe('ปกติ')
        ->and($sheet->getCell('G'.($row - 1))->getValue())->toBe('ผิดปกติ')
        ->and($sheet->getCell('A2')->getValue())->toContain('รอบที่')->toContain('2569')
        ->and($sheet->getCell('A3')->getValue())->toContain('Acme')
        ->and($sheet->getCell("B{$row}")->getValue())->toBe($this->a->asset_code)
        ->and($sheet->getCell("C{$row}")->getValue())->toBe('Cisco C2960X')
        ->and($sheet->getCell("D{$row}")->getValue())->toBe('FCW2041A1G8')
        ->and($sheet->getCell("E{$row}")->getValue())->toBe('Server Room · ชั้น 2')
        ->and((int) $sheet->getCell("J{$row}")->getValue())->toBe(($this->itemOf)($this->a)->id)
        ->and($sheet->getColumnDimension('J')->getVisible())->toBeFalse();

    // Customer accounts do not export the work in progress.
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get("{$this->url}/results.xlsx")->assertForbidden();
});

it('records what was ticked: Pass as fine, Fail as a problem with its remark, blank rows left alone', function () {
    $book = ($this->download)();
    $sheet = $book->getActiveSheet();
    $first = ExportPmResultSheet::FIRST_ROW;
    $sheet->setCellValue("F{$first}", '✓');
    $sheet->setCellValue('G'.($first + 1), '/');
    $sheet->setCellValue('I'.($first + 1), 'พัดลมเสียงดัง');

    ($this->upload)($book)->assertSessionHasNoErrors()->assertSessionHas('success', 'นำเข้าผลตรวจจาก Excel แล้ว 2 เครื่อง');

    $a = ($this->itemOf)($this->a);
    $b = ($this->itemOf)($this->b);
    expect($a->result)->toBe('passed')
        ->and($a->answers)->toMatchArray(['fan' => 'pass', 'clean' => 'pass'])
        ->and($a->checked_by)->toBe($this->tech->id)
        ->and($b->result)->toBe('failed')
        ->and($b->note)->toBe('พัดลมเสียงดัง');

    // Exported again: the ticks are there.
    $sheet = ($this->download)()->getActiveSheet();
    expect($sheet->getCell("F{$first}")->getValue())->toBe('✓')
        ->and($sheet->getCell('G'.($first + 1))->getValue())->toBe('✓');
});

it('reports a row ticked twice and keeps the others, and refuses another work order\'s sheet', function () {
    $book = ($this->download)();
    $sheet = $book->getActiveSheet();
    $first = ExportPmResultSheet::FIRST_ROW;
    $sheet->setCellValue("F{$first}", '✓');
    $sheet->setCellValue("G{$first}", '✓');
    $sheet->setCellValue('H'.($first + 1), 'x');
    $sheet->setCellValue('I'.($first + 1), 'ห้องล็อก');

    ($this->upload)($book)->assertSessionHasErrors('file');
    expect(($this->itemOf)($this->a)->result)->toBe('pending')
        ->and(($this->itemOf)($this->b)->result)->toBe('not_inspected')
        // Not one of the reasons: "other", the words as the note.
        ->and(($this->itemOf)($this->b)->not_inspected_reason)->toBe('other')
        ->and(($this->itemOf)($this->b)->note)->toBe('ห้องล็อก');

    // A reason by its words, the rest as the note; exported the same way.
    $sheet->setCellValue("F{$first}", null);
    $sheet->setCellValue("G{$first}", null);
    $sheet->setCellValue('I'.($first + 1), 'เครื่องส่งซ่อม · ส่งศูนย์ Cisco');
    ($this->upload)($book)->assertSessionHasNoErrors();
    expect(($this->itemOf)($this->b)->only(['not_inspected_reason', 'note']))->toBe(['not_inspected_reason' => 'sent_for_repair', 'note' => 'ส่งศูนย์ Cisco'])
        ->and(($this->download)()->getActiveSheet()->getCell('I'.($first + 1))->getValue())->toBe('เครื่องส่งซ่อม · ส่งศูนย์ Cisco');

    // Item references that are not of this work order.
    $sheet->setCellValue("J{$first}", 999999);
    $sheet->setCellValue('J'.($first + 1), 999998);
    ($this->upload)($book)->assertSessionHasErrors(['file' => __('maintenance.result_sheet.not_this_visit')]);
});

it('imports only while the work is going on, by who performs it', function () {
    $book = ($this->download)();
    $other = userWithRole('technician');
    $path = tempnam(sys_get_temp_dir(), 'pmr').'.xlsx';
    (new Xlsx($book))->save($path);

    $this->actingAs($other)->post("{$this->url}/results", ['file' => new UploadedFile($path, 'r.xlsx', null, null, true)])->assertForbidden();
});
