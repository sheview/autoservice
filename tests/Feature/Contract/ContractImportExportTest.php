<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->beta = createCustomer(['code' => 'BETA', 'name' => 'Beta']);
});

/**
 * An uploaded .xlsx file with these rows (first row = headings).
 */
function contractsXlsx(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'contracts').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'contracts.xlsx', null, null, true);
}

function contractSheetRows(string $path): array
{
    return Excel::toCollection(null, $path)->first()->toArray();
}

/** @return array<string, array{int, int}> priority => [response minutes, resolve minutes] */
function contractSlas(Contract $contract): array
{
    return $contract->slas()->get()->mapWithKeys(fn ($sla) => [$sla->priority => [$sla->response_minutes, $sla->resolve_minutes]])->sortKeys()->all();
}

const CONTRACT_HEADINGS = [
    'เลขที่สัญญา', 'รหัสลูกค้า', 'ชื่อสัญญา', 'สถานะ', 'วันเริ่มสัญญา', 'วันสิ้นสุดสัญญา', 'มูลค่าสัญญา (บาท)',
    'ช่วงเวลาให้บริการ', 'รอบ PM (เดือน)', 'ตอบสนอง วิกฤต (ชม.)', 'แก้ไข วิกฤต (ชม.)',
];

it('imports new contracts, updates existing numbers and reports bad rows by row number', function () {
    $existing = createContract($this->acme, ['contract_no' => 'MA-ACME', 'notes' => 'keep me', 'notify_days_before' => 45,
        'slas' => ['high' => ['response_minutes' => 120, 'resolve_minutes' => 480]]]);

    $this->actingAs($this->admin)->post('/contracts/import', ['file' => contractsXlsx([
        CONTRACT_HEADINGS,
        ['MA-ACME', 'ACME', 'MA ใหม่', 'ใช้งาน', '01/01/2569', '31/12/2569', '120,000.50', '24X7', 3, 0.5, 4],
        ['MA-NEW', 'beta', 'MA Beta', 'draft', '2026-03-01', '2027-02-28', null, '8x5', null, null, null],
        [2569001, 'ACME', 'Numeric number', null, '2026-01-01', '2026-12-31', 5000, '12x6', null, 1, 8],
        ['MA-BAD', 'NOPE', 'Unknown customer', 'maybe', '2026-01-01', '2026-12-31', null, '8x5', null, null, null],
        ['MA-BAD2', 'ACME', 'Wrong dates', null, '2026-12-31', '2026-01-01', null, '9x9', 5, 4, 1],
        [null, null, null, null, null, null, null, null, null, null, null],
    ])])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contract/Contracts/Import')
        ->where('result.ok', true)
        ->where('result.total', 5)
        ->where('result.created', 2)
        ->where('result.updated', 1)
        ->where('result.failed', 2)
        ->where('result.errors.0.row', 5)
        ->where('result.errors.0.messages', fn ($messages) => collect($messages)->contains('ไม่พบลูกค้ารหัส "NOPE"')
            && collect($messages)->contains(fn ($m) => str_contains($m, 'ไม่รู้จักสถานะ "maybe"')))
        ->where('result.errors.1.row', 6)
        ->where('result.errors.1.messages', fn ($messages) => count($messages) === 4));

    // the existing contract: data updated (Buddhist-era dates), columns missing from the file kept, SLA merged
    $existing->refresh();
    expect($existing->only(['title', 'status', 'value', 'service_window', 'pm_interval_months', 'notify_days_before', 'notes']))->toBe([
        'title' => 'MA ใหม่', 'status' => 'active', 'value' => 12000050, 'service_window' => '24x7',
        'pm_interval_months' => 3, 'notify_days_before' => 45, 'notes' => 'keep me',
    ])
        ->and($existing->starts_on->toDateString())->toBe('2026-01-01')
        ->and($existing->ends_on->toDateString())->toBe('2026-12-31')
        ->and(contractSlas($existing))->toBe(['critical' => [30, 240], 'high' => [120, 480]]);

    $new = Contract::where('contract_no', 'MA-NEW')->first();
    expect($new->only(['customer_id', 'status', 'value', 'notify_days_before']))
        ->toBe(['customer_id' => $this->beta->id, 'status' => 'draft', 'value' => null, 'notify_days_before' => 60])
        ->and(contractSlas($new))->toBe([])
        ->and(Contract::where('contract_no', '2569001')->value('value'))->toBe(500000)
        ->and(Contract::count())->toBe(3);
});

it('fails a file without the required headings or that is not a spreadsheet', function () {
    $this->actingAs($this->admin)->post('/contracts/import', ['file' => contractsXlsx([['foo', 'bar'], ['1', '2']])])
        ->assertInertia(fn (Assert $page) => $page->where('result.ok', false)
            ->where('result.errors.0.messages.0', fn ($message) => str_contains($message, 'ไฟล์ต้นแบบ')));

    $this->actingAs($this->admin)->post('/contracts/import', ['file' => UploadedFile::fake()->create('evil.php', 1)])
        ->assertSessionHasErrors('file');

    expect(Contract::count())->toBe(0);
});

it('refuses the number of a deleted contract', function () {
    createContract($this->acme, ['contract_no' => 'GONE'])->delete();

    $this->actingAs($this->admin)->post('/contracts/import', ['file' => contractsXlsx([
        CONTRACT_HEADINGS,
        ['GONE', 'ACME', 'Back again', null, '2026-01-01', '2026-12-31', null, '8x5', null, null, null],
    ])])->assertInertia(fn (Assert $page) => $page->where('result.failed', 1)->where('result.created', 0));

    expect(Contract::count())->toBe(0);
});

it('exports the filtered list and imports the same file back as updates', function () {
    createContract($this->acme, ['contract_no' => 'MA-ACME', 'title' => 'MA Acme', 'value' => 12000050, 'service_window' => '24x7',
        'pm_interval_months' => 3, 'notes' => 'ชั้น A', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'slas' => ['critical' => ['response_minutes' => 30, 'resolve_minutes' => 240]]]);
    createContract($this->beta, ['contract_no' => 'MA-BETA', 'status' => Contract::STATUS_DRAFT]);

    $acmeOnly = $this->actingAs($this->admin)->get("/contracts/export?customer_id={$this->acme->id}")->assertOk();
    $rows = contractSheetRows($acmeOnly->getFile()->getPathname());
    expect(array_slice($rows[0], 0, 11))->toBe([...array_slice(CONTRACT_HEADINGS, 0, 9), 'แจ้งเตือนก่อนหมดอายุ (วัน)', 'หมายเหตุ'])
        ->and($rows[0])->toHaveCount(19)
        ->and($rows)->toHaveCount(2)
        ->and(array_slice($rows[1], 0, 13))->toEqual([
            'MA-ACME', 'ACME', 'MA Acme', 'ใช้งาน', '2026-01-01', '2026-12-31', 120000.5, '24x7', 3, 60, 'ชั้น A', 0.5, 4,
        ]);

    $all = $this->actingAs($this->admin)->get('/contracts/export')->assertOk();
    $file = new UploadedFile($all->getFile()->getPathname(), 'export.xlsx', null, null, true);
    $before = Contract::with('slas')->orderBy('id')->get()->map(fn ($c) => [$c->only(['customer_id', 'title', 'status', 'value', 'service_window', 'pm_interval_months', 'notify_days_before', 'notes']), contractSlas($c)]);

    $this->actingAs($this->admin)->post('/contracts/import', ['file' => $file])
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 0)->where('result.updated', 2)->where('result.failed', 0));

    $after = Contract::with('slas')->orderBy('id')->get()->map(fn ($c) => [$c->only(['customer_id', 'title', 'status', 'value', 'service_window', 'pm_interval_months', 'notify_days_before', 'notes']), contractSlas($c)]);
    expect($after->all())->toBe($before->all());
});

it('downloads an empty template', function () {
    $response = $this->actingAs($this->admin)->get('/contracts/import/template')->assertOk();

    $rows = contractSheetRows($response->getFile()->getPathname());
    expect($rows)->toHaveCount(1)->and($rows[0])->toContain('เลขที่สัญญา', 'รหัสลูกค้า', 'แก้ไข ต่ำ (ชม.)');
});

it('requires contracts.import and contracts.export', function () {
    $helpdesk = userWithRole('helpdesk');

    $this->actingAs($helpdesk)->get('/contracts/import')->assertForbidden();
    $this->actingAs($helpdesk)->post('/contracts/import', ['file' => contractsXlsx([CONTRACT_HEADINGS])])->assertForbidden();
    $this->actingAs($helpdesk)->get('/contracts/import/template')->assertForbidden();
    $this->actingAs($helpdesk)->get('/contracts/export')->assertForbidden();
    $this->actingAs($helpdesk)->get('/contracts')->assertInertia(fn (Assert $page) => $page->where('can.import', false)->where('can.export', false));
    $this->actingAs($this->admin)->get('/contracts')->assertInertia(fn (Assert $page) => $page->where('can.import', true)->where('can.export', true));
});

it('keeps each row to the reach of the user', function () {
    $technician = userWithRole('technician');
    grantTo('technician', ['contracts.import', 'contracts.create', 'contracts.update', 'contracts.export'], PermissionCatalog::SCOPE_OWN);
    $beta = createContract($this->beta, ['contract_no' => 'MA-BETA', 'title' => 'Beta']);
    $row = fn (string $no, string $customer) => [$no, $customer, 'From file', null, '2026-01-01', '2026-12-31', null, '8x5', null, null, null];

    // no tickets yet: no customer is theirs
    $this->actingAs($technician)->post('/contracts/import', ['file' => contractsXlsx([CONTRACT_HEADINGS, $row('MA-ACME', 'ACME')])])
        ->assertInertia(fn (Assert $page) => $page->where('result.failed', 1)
            ->where('result.errors.0.messages.0', 'คุณไม่มีสิทธิ์ทำสัญญาให้ลูกค้ารหัส "ACME"'));

    openTicket($this->admin, ['customer_id' => $this->acme->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->post('/contracts/import', ['file' => contractsXlsx([
        CONTRACT_HEADINGS, $row('MA-ACME', 'ACME'), $row('MA-BETA', 'BETA'), $row('MA-MOVE', 'BETA'),
    ])])->assertInertia(fn (Assert $page) => $page->where('result.created', 1)->where('result.failed', 2)
        ->where('result.errors.0.messages.0', 'คุณไม่มีสิทธิ์แก้ไขหรือเพิ่มสัญญา "MA-BETA"'));

    expect($beta->fresh()->title)->toBe('Beta')
        ->and(Contract::where('contract_no', 'MA-ACME')->value('customer_id'))->toBe($this->acme->id);

    // the export holds only the contracts they reach
    $rows = contractSheetRows($this->actingAs($technician)->get('/contracts/export')->getFile()->getPathname());
    expect(array_column(array_slice($rows, 1), 0))->toBe(['MA-ACME']);
});

it('imports only into the own tenant', function () {
    $other = createTenant('other');
    asTenant($other, function () {
        $theirs = createCustomer(['code' => 'THEM']);
        createContract($theirs, ['contract_no' => 'MA-1', 'title' => 'Their contract']);
    });

    $this->actingAs($this->admin)->post('/contracts/import', ['file' => contractsXlsx([
        CONTRACT_HEADINGS,
        ['MA-1', 'ACME', 'My contract', null, '2026-01-01', '2026-12-31', null, '8x5', null, null, null],
        ['MA-2', 'THEM', 'Their customer', null, '2026-01-01', '2026-12-31', null, '8x5', null, null, null],
    ])])->assertInertia(fn (Assert $page) => $page->where('result.created', 1)->where('result.updated', 0)->where('result.failed', 1)
        ->where('result.errors.0.messages.0', 'ไม่พบลูกค้ารหัส "THEM"'));

    expect(Contract::pluck('title')->all())->toBe(['My contract']);
    asTenant($other, fn () => expect(Contract::pluck('title')->all())->toBe(['Their contract']));

    $rows = contractSheetRows($this->actingAs($this->admin)->get('/contracts/export')->getFile()->getPathname());
    expect(array_column(array_slice($rows, 1), 2))->toBe(['My contract']);
});
