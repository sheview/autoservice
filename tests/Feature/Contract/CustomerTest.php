<?php

use App\Modules\Contract\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;

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
