<?php

use App\Modules\Contract\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('creates a customer with an upper-case code', function () {
    $this->actingAs($this->admin)->post('/customers', [
        'code' => 'acme-01', 'name' => 'บริษัท แอคมี จำกัด', 'tax_id' => '0105561234567',
        'contact_name' => 'คุณสมชาย', 'phone' => '02-123-4567', 'email' => 'it@acme.test',
    ])->assertRedirect('/customers')->assertSessionHasNoErrors();

    expect(Customer::first()->only(['code', 'name', 'tenant_id']))
        ->toBe(['code' => 'ACME-01', 'name' => 'บริษัท แอคมี จำกัด', 'tenant_id' => $this->tenant->id]);
});

it('gives a new customer the next CUST code when none is typed, and keeps the code when editing', function () {
    createCustomer(['code' => 'CUST007']);
    createCustomer(['code' => 'ACME']);

    $this->actingAs($this->admin)->post('/customers', ['code' => '', 'name' => 'ลูกค้าใหม่'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/customers', ['name' => 'ลูกค้าใหม่ 2'])->assertSessionHasNoErrors();
    expect(Customer::where('name', 'like', 'ลูกค้าใหม่%')->orderBy('id')->pluck('code')->all())->toBe(['CUST008', 'CUST009']);

    $customer = Customer::where('code', 'CUST008')->sole();
    $this->actingAs($this->admin)->put("/customers/{$customer->id}", ['code' => '', 'name' => 'X'])->assertSessionHasErrors('code');
});

it('rejects a duplicate code ignoring case and a bad e-mail', function () {
    createCustomer(['code' => 'ACME']);

    $this->actingAs($this->admin)->post('/customers', ['code' => 'acme', 'name' => 'X', 'email' => 'not-an-email'])
        ->assertSessionHasErrors(['code', 'email']);
});

it('lists customers with search, sort and contract counts', function () {
    $acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    createCustomer(['code' => 'BETA', 'name' => 'Beta', 'contact_name' => 'Somsri']);
    createContract($acme);

    $this->actingAs($this->admin)->get('/customers?search=somsri')
        ->assertInertia(fn (Assert $page) => $page->component('Contract/Customers/Index')
            ->where('customers.total', 1)->where('customers.data.0.name', 'Beta'));

    $this->actingAs($this->admin)->get('/customers?sort=contracts_count&direction=desc')
        ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.name', 'Acme')->where('customers.data.0.contracts_count', 1));
});

it('deletes only a customer without contracts or assets', function () {
    $withContract = createCustomer(['name' => 'With contract']);
    createContract($withContract);
    $withAsset = createCustomer(['name' => 'With asset']);
    createAsset(createAssetCategory(), ['customer_id' => $withAsset->id]);
    $free = createCustomer(['name' => 'Free']);

    $this->actingAs($this->admin)->delete("/customers/{$withContract->id}")->assertSessionHasErrors('customer');
    $this->actingAs($this->admin)->delete("/customers/{$withAsset->id}")->assertSessionHasErrors('customer');
    $this->actingAs($this->admin)->delete("/customers/{$free->id}")->assertSessionHasNoErrors();

    expect(Customer::pluck('name')->sort()->values()->all())->toBe(['With asset', 'With contract']);
});

it('lets a technician view but not change customers', function () {
    $technician = userWithRole('technician');
    $customer = createCustomer();
    openTicket($this->admin, ['customer_id' => $customer->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get('/customers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
    $this->actingAs($technician)->post('/customers', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($technician)->put("/customers/{$customer->id}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($technician)->delete("/customers/{$customer->id}")->assertForbidden();

    $this->actingAs(userWithRole('user'))->get('/customers')->assertForbidden();
});

it('gives the forms the signature setting as true / false / null, so they show what is saved', function () {
    $customer = createCustomer(['require_signature' => true]);
    $inherit = createContract($customer);
    $no = createContract($customer, ['require_signature' => false]);

    $this->actingAs($this->admin)->get("/customers/{$customer->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('customer.require_signature', true));
    $this->actingAs($this->admin)->get("/contracts/{$inherit->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('contract.require_signature', null));
    $this->actingAs($this->admin)->get("/contracts/{$no->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('contract.require_signature', false));
});

it('exports the customer list with the search and sort on screen, every page', function () {
    createCustomer(['code' => 'ACME', 'name' => 'Acme', 'tax_id' => '0105561234567', 'contact_name' => 'คุณสมชาย', 'phone' => '02-123-4567']);
    createCustomer(['code' => 'BETA', 'name' => 'Beta']);
    createCustomer(['code' => 'ZED', 'name' => 'Zed']);

    $all = $this->actingAs($this->admin)->get('/customers/export?sort=code&direction=desc')->assertOk();
    $rows = Excel::toCollection(null, $all->getFile()->getPathname())->first()->toArray();
    expect($rows[0])->toBe(['รหัสลูกค้า', 'ชื่อลูกค้า', 'ชื่อย่อ', 'เลขประจำตัวผู้เสียภาษี', 'ผู้ติดต่อ', 'เบอร์โทรศัพท์', 'อีเมล', 'ที่อยู่', 'สัญญา', 'หมายเหตุ'])
        ->and(array_column(array_slice($rows, 1), 0))->toBe(['ZED', 'BETA', 'ACME']);

    $acme = $this->actingAs($this->admin)->get('/customers/export?search=สมชาย')->assertOk();
    $rows = Excel::toCollection(null, $acme->getFile()->getPathname())->first()->toArray();
    expect($rows)->toHaveCount(2)
        ->and(array_slice($rows[1], 0, 6))->toEqual(['ACME', 'Acme', null, '0105561234567', 'คุณสมชาย', '02-123-4567']);
});

it('exports only the customers the user may see, of the own company', function () {
    createCustomer(['code' => 'MINE', 'name' => 'Mine']);
    asTenant(createTenant('other'), fn () => createCustomer(['code' => 'THEIRS', 'name' => 'Theirs']));

    $response = $this->actingAs($this->admin)->get('/customers/export')->assertOk();
    $rows = Excel::toCollection(null, $response->getFile()->getPathname())->first()->toArray();
    expect(array_column(array_slice($rows, 1), 0))->toBe(['MINE']);

    // a technician (scope own) with no tickets sees no customer
    $response = $this->actingAs(userWithRole('technician'))->get('/customers/export')->assertOk();
    expect(Excel::toCollection(null, $response->getFile()->getPathname())->first()->toArray())->toHaveCount(1);

    $this->actingAs(userWithRole('customer_it', ['customer_id' => Customer::first()->id]))->get('/customers/export')->assertForbidden();
});
