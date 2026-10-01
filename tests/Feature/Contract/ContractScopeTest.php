<?php

use App\Modules\Contract\Actions\SearchContracts;
use Inertia\Testing\AssertableInertia as Assert;

function scopedContractPayload(array $overrides): array
{
    return $overrides + [
        'contract_no' => 'MA-EDIT', 'title' => 'MA', 'status' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'service_window' => '8x5', 'notify_days_before' => 30,
    ];
}

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->beta = createCustomer(['code' => 'BETA', 'name' => 'Beta']);
    $this->acmeContract = createContract($this->acme, ['contract_no' => 'MA-ACME']);
    $this->betaContract = createContract($this->beta, ['contract_no' => 'MA-BETA']);
});

it('shows a technician (scope own) only the customers and contracts of their own tickets', function () {
    $technician = userWithRole('technician');
    $this->actingAs($technician)->get('/contracts')->assertInertia(fn (Assert $page) => $page->where('contracts.total', 0)->where('customers', []));

    openTicket($this->admin, ['customer_id' => $this->acme->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get('/contracts')->assertInertia(fn (Assert $page) => $page
        ->where('contracts.total', 1)->where('contracts.data.0.contract_no', 'MA-ACME')
        ->where('customers', [['id' => $this->acme->id, 'code' => 'ACME', 'name' => 'Acme']]));
    $this->actingAs($technician)->get("/contracts/{$this->acmeContract->id}")->assertOk();
    $this->actingAs($technician)->get("/contracts/{$this->betaContract->id}")->assertForbidden();
    $this->actingAs($technician)->get('/customers')->assertInertia(fn (Assert $page) => $page
        ->where('customers.total', 1)->where('customers.data.0.code', 'ACME')->where('customers.data.0.can.update', false));
    expect(app(SearchContracts::class)->handle([], $technician)->pluck('contract_no')->all())->toBe(['MA-ACME']);

    // tickets they reported count too
    $helpdesk = userWithRole('helpdesk');
    openTicket($technician, ['customer_id' => $this->beta->id]);
    $this->actingAs($technician)->get('/contracts')->assertInertia(fn (Assert $page) => $page->where('contracts.total', 2));
    $this->actingAs($helpdesk)->get('/contracts')->assertInertia(fn (Assert $page) => $page->where('contracts.total', 2));
});

it('shows a customer account only the contracts of its own customer', function () {
    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);

    $this->actingAs($client)->get('/contracts')->assertInertia(fn (Assert $page) => $page
        ->where('contracts.total', 1)->where('contracts.data.0.contract_no', 'MA-ACME')
        ->where('customers', [['id' => $this->acme->id, 'code' => 'ACME', 'name' => 'Acme']])
        ->where('can.create', false));
    $this->actingAs($client)->get("/contracts/{$this->acmeContract->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false));
    $this->actingAs($client)->get("/contracts/{$this->betaContract->id}")->assertForbidden();
    $this->actingAs($client)->get("/contracts?customer_id={$this->beta->id}")->assertInertia(fn (Assert $page) => $page->where('contracts.total', 0));
    $this->actingAs($client)->get('/customers')->assertForbidden();
});

it('lets a customer account with customers.view see only its own customer', function () {
    grantTo('customer_it', ['customers.view'], 'customer');
    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);

    $this->actingAs($client)->get('/customers')->assertInertia(fn (Assert $page) => $page
        ->where('customers.total', 1)->where('customers.data.0.code', 'ACME'));
});

it('keeps new and changed contracts to customers within the scope of the permission', function () {
    $technician = userWithRole('technician');
    grantTo('technician', ['contracts.create', 'contracts.update'], 'own');
    openTicket($this->admin, ['customer_id' => $this->acme->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get('/contracts/create')->assertInertia(fn (Assert $page) => $page->has('customers', 1));
    $this->actingAs($technician)->post('/contracts', scopedContractPayload(['customer_id' => $this->beta->id, 'contract_no' => 'MA-NEW']))
        ->assertSessionHasErrors('customer_id');
    $this->actingAs($technician)->post('/contracts', scopedContractPayload(['customer_id' => $this->acme->id, 'contract_no' => 'MA-NEW']))
        ->assertSessionHasNoErrors();
    $this->actingAs($technician)->put("/contracts/{$this->betaContract->id}", scopedContractPayload(['customer_id' => $this->beta->id]))->assertForbidden();
});
