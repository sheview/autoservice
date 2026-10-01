<?php

use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->customer = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->contract = createContract($this->customer, ['contract_no' => 'MA-1']);
    $this->category = createAssetCategory();

    $this->mine = createAsset($this->category, ['name' => 'Acme Switch', 'customer_id' => $this->customer->id]);
    $this->alsoMine = createAsset($this->category, ['name' => 'Acme Router', 'customer_id' => $this->customer->id]);
    $this->otherCustomers = createAsset($this->category, ['name' => 'Beta PC', 'customer_id' => createCustomer()->id]);
});

it('saves the customer of an asset and filters assets by customer', function () {
    $this->actingAs($this->admin)->get("/assets?customer_id={$this->customer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 2)
            ->where('assets.data.0.customer', 'Acme')
            ->where('customers', fn ($customers) => collect($customers)->pluck('code')->contains('ACME')));
});

it('finds candidates of the contract customer only, then adds and removes them', function () {
    $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}?asset_search=acme")
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Acme Router', 'Acme Switch']));

    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id, $this->alsoMine->id]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}?asset_search=acme")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assetCount', 2)
            ->where('assets', fn ($rows) => count($rows) === 2)
            ->where('candidates', []));

    $this->actingAs($this->admin)->delete("/contracts/{$this->contract->id}/assets/{$this->mine->id}")->assertSessionHasNoErrors();

    expect($this->contract->contractAssets()->pluck('asset_id')->all())->toBe([$this->alsoMine->id]);
});

it('does not add an asset of another customer or twice', function () {
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->otherCustomers->id]])
        ->assertSessionHasErrors('asset_ids');

    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id]]);
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id]]);

    expect($this->contract->contractAssets()->count())->toBe(1);
});

it('shows on the asset page which contracts cover it', function () {
    $renewal = createContract($this->customer, [
        'contract_no' => 'MA-2', 'starts_on' => now()->addMonths(11)->addDay()->toDateString(), 'ends_on' => now()->addMonths(23)->toDateString(),
    ]);
    $this->contract->contractAssets()->create(['asset_id' => $this->mine->id]);
    $renewal->contractAssets()->create(['asset_id' => $this->mine->id]);

    $this->actingAs($this->admin)->get("/assets/{$this->mine->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('asset.customer', 'Acme')
            ->where('contracts.0.contract_no', 'MA-2')
            ->where('contracts.0.phase', 'upcoming')
            ->where('contracts.0.covering', false)
            ->where('contracts.1.contract_no', 'MA-1')
            ->where('contracts.1.covering', true));

    // no contracts.view -> no contract section
    grantTo('user', ['assets.view']);
    $this->actingAs(userWithRole('user'))->get("/assets/{$this->mine->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('contracts', null));
});

it('hides covered assets of other branches from a user with branch scope on assets', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    $this->mine->update(['branch_id' => $north->id]);
    $this->alsoMine->update(['branch_id' => $south->id]);
    $this->contract->contractAssets()->create(['asset_id' => $this->mine->id]);
    $this->contract->contractAssets()->create(['asset_id' => $this->alsoMine->id]);

    // the technician works on a ticket of this customer, so its contracts are theirs to see (scope own)
    $technician = userWithRole('technician', ['branch_id' => $north->id]);
    openTicket($this->admin, ['customer_id' => $this->customer->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get("/contracts/{$this->contract->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assetCount', 2)
            ->where('assets', fn ($rows) => collect($rows)->pluck('name')->all() === ['Acme Switch'])
            ->where('can.manageAssets', false));
});

it('keeps asset exports and imports in step with the customer column', function () {
    $response = $this->actingAs($this->admin)->get('/assets/export?search=Acme+Switch')->assertOk();
    $rows = Excel::toCollection(null, $response->getFile()->getPathname())->first();

    expect($rows[1][4])->toBe('ACME');
});
