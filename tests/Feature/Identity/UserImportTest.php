<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const USER_HEADINGS = ['ชื่อ-นามสกุล', 'อีเมล', 'รหัสผ่าน', 'บทบาท', 'สาขา', 'ลูกค้า', 'รหัสพนักงาน', 'ตำแหน่ง', 'เบอร์โทรศัพท์'];

/**
 * An uploaded .xlsx file of accounts (first row = headings).
 */
function userSheetUpload(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'users').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'users.xlsx', null, null, true);
}

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->branch = Branch::create(['code' => 'BKK', 'name' => 'สำนักงานใหญ่']);
});

it('creates staff and customer accounts and reports skipped and bad rows by row number', function () {
    $customer = createCustomer(['code' => 'CUST001', 'name' => 'ลูกค้า A']);
    userWithRole('helpdesk', ['email' => 'old@example.com']);

    $this->actingAs($this->admin)->post('/users/import', ['file' => userSheetUpload([
        USER_HEADINGS,
        ['สมชาย ใจดี', 'Somchai@Example.com', 'Secret-123', 'เจ้าหน้าที่ Helpdesk', 'BKK', null, 'E001', 'Helpdesk', '0812345678'],
        ['ลูกค้า หนึ่ง', 'it@customer.test', 'Secret-123', 'customer_it', null, 'CUST001', null, null, null],
        ['บัญชีเดิม', 'old@example.com', 'Secret-123', 'helpdesk', null, null, null, null, null],
        ['รหัสง่าย', 'weak@example.com', 'password', 'helpdesk', null, null, null, null, null],
        ['ไม่มีบทบาท', 'norole@example.com', 'Secret-123', 'ผู้วิเศษ', 'XYZ', null, null, null, null],
        ['ลูกค้าผิดบทบาท', 'mixed@example.com', 'Secret-123', 'helpdesk', null, 'CUST001', null, null, null],
        [null, null, null, null, null, null, null, null, null],
    ])])->assertRedirect('/users/import');

    $somchai = User::where('email', 'somchai@example.com')->sole();
    expect($somchai->only(['name', 'branch_id', 'employee_code', 'phone', 'is_active']))
        ->toBe(['name' => 'สมชาย ใจดี', 'branch_id' => $this->branch->id, 'employee_code' => 'E001', 'phone' => '0812345678', 'is_active' => true])
        ->and($somchai->hasRole('helpdesk'))->toBeTrue()
        ->and(Hash::check('Secret-123', $somchai->password))->toBeTrue();

    $account = User::where('email', 'it@customer.test')->sole();
    expect($account->customer_id)->toBe($customer->id)
        ->and($account->hasRole('customer_it'))->toBeTrue();

    expect(User::whereIn('email', ['weak@example.com', 'norole@example.com', 'mixed@example.com'])->exists())->toBeFalse();

    $this->actingAs($this->admin)->get('/users/import')
        ->assertInertia(fn (Assert $page) => $page->component('Identity/Users/Import')
            ->where('result.total', 6)
            ->where('result.created', 2)
            ->where('result.skipped', 1)
            ->where('result.failed', 3)
            ->where('result.errors.0', ['row' => 4, 'messages' => ['ข้าม: มีบัญชีอีเมล old@example.com อยู่แล้ว']])
            ->where('result.errors.1.row', 5)
            ->where('result.errors.1.messages', fn ($messages) => collect($messages)->contains(fn ($m) => str_contains($m, 'ตัวพิมพ์ใหญ่')))
            ->where('result.errors.2.messages', ['ไม่พบบทบาท "ผู้วิเศษ"', 'ไม่พบสาขา "XYZ"'])
            ->where('result.errors.3.messages', ['บัญชีที่ผูกกับลูกค้าต้องใช้บทบาทบัญชีลูกค้าเท่านั้น']));

    // The result is shown once.
    $this->actingAs($this->admin)->get('/users/import')->assertInertia(fn (Assert $page) => $page->where('result', null));
});

it('skips accounts already in the file or in another company on a second run', function () {
    $other = createTenant('other');
    userWithRole('helpdesk', ['email' => 'taken@example.com'], $other);
    $rows = [
        USER_HEADINGS,
        ['ใหม่', 'new@example.com', 'Secret-123', 'helpdesk'],
        ['ซ้ำในไฟล์', 'NEW@example.com', 'Secret-123', 'helpdesk'],
        ['บริษัทอื่น', 'taken@example.com', 'Secret-123', 'helpdesk'],
    ];

    $this->actingAs($this->admin)->post('/users/import', ['file' => userSheetUpload($rows)]);
    $this->actingAs($this->admin)->get('/users/import')
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 1)->where('result.skipped', 2));

    $this->actingAs($this->admin)->post('/users/import', ['file' => userSheetUpload($rows)]);
    $this->actingAs($this->admin)->get('/users/import')
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 0)->where('result.skipped', 3));

    expect(User::where('email', 'new@example.com')->sole()->tenant_id)->toBe($this->admin->tenant_id);
});

it('rejects a file without the required headings', function () {
    $this->actingAs($this->admin)->post('/users/import', ['file' => userSheetUpload([['ชื่อ', 'อีเมล'], ['x', 'x@example.com']])]);

    $this->actingAs($this->admin)->get('/users/import')
        ->assertInertia(fn (Assert $page) => $page->where('result.total', 0)->where('result.errors.0.row', null));
    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

it('downloads the template and needs users.manage', function () {
    $this->actingAs($this->admin)->get('/users/import/template')->assertOk()->assertDownload('user-import-template.xlsx');

    $helpdesk = userWithRole('helpdesk');
    $this->actingAs($helpdesk)->get('/users/import')->assertForbidden();
    $this->actingAs($helpdesk)->get('/users/import/template')->assertForbidden();
    $this->actingAs($helpdesk)->post('/users/import', ['file' => userSheetUpload([USER_HEADINGS])])->assertForbidden();
});

it('lets a manager limited to their branch import only into that branch', function () {
    $other = Branch::create(['code' => 'CNX', 'name' => 'เชียงใหม่']);
    grantTo('helpdesk', ['users.view', 'users.manage'], 'branch');
    $manager = userWithRole('helpdesk', ['branch_id' => $this->branch->id]);

    $this->actingAs($manager)->post('/users/import', ['file' => userSheetUpload([
        USER_HEADINGS,
        ['ในสาขา', 'mine@example.com', 'Secret-123', 'helpdesk', 'BKK'],
        ['สาขาอื่น', 'theirs@example.com', 'Secret-123', 'helpdesk', 'CNX'],
    ])]);

    expect(User::where('email', 'mine@example.com')->value('branch_id'))->toBe($this->branch->id)
        ->and(User::where('email', 'theirs@example.com')->exists())->toBeFalse();
});

it('only finds branches, roles and customers of the current company', function () {
    $other = createTenant('other');
    asTenant($other, function () {
        Branch::create(['code' => 'FAR', 'name' => 'ไกล']);
        createCustomer(['code' => 'THEIRS', 'name' => 'ลูกค้าบริษัทอื่น']);
    });

    $this->actingAs($this->admin)->post('/users/import', ['file' => userSheetUpload([
        USER_HEADINGS,
        ['สาขาอื่น', 'a@example.com', 'Secret-123', 'helpdesk', 'FAR'],
        ['ลูกค้าอื่น', 'b@example.com', 'Secret-123', 'customer_it', null, 'THEIRS'],
    ])]);

    $this->actingAs($this->admin)->get('/users/import')
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 0)->where('result.failed', 2)
            ->where('branches', [['code' => 'BKK', 'name' => 'สำนักงานใหญ่']]));
    expect(asTenant($other, fn () => User::count()))->toBe(0);
});
