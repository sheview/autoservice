<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Document\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = userWithRole('admin_company');
    $this->mine = createContract(createCustomer(['code' => 'MINE', 'name' => 'My customer']), ['contract_no' => 'MA-1']);

    $this->other = createTenant('other');
    [$this->theirCustomer, $this->theirContract, $this->theirAsset, $this->theirMedia] = asTenant($this->other, function () {
        $customer = createCustomer(['code' => 'MINE', 'name' => 'Their customer']);
        $contract = createContract($customer, ['contract_no' => 'MA-1']);
        $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
        $contract->contractAssets()->create(['asset_id' => $asset->id]);
        $media = $contract->addMedia(UploadedFile::fake()->createWithContent('theirs.pdf', '%PDF-1.4'))->toMediaCollection('documents');

        return [$customer, $contract, $asset, $media];
    });
});

it('lists only customers and contracts of the own tenant (same codes allowed)', function () {
    $this->actingAs($this->admin)->get('/customers')
        ->assertInertia(fn (Assert $page) => $page->where('customers.total', 1)->where('customers.data.0.name', 'My customer'));

    $this->actingAs($this->admin)->get('/contracts')
        ->assertInertia(fn (Assert $page) => $page->where('contracts.total', 1)->where('contracts.data.0.customer', 'My customer'));
});

it('returns 404 for customers, contracts and files of another tenant', function () {
    $this->actingAs($this->admin)->get("/customers/{$this->theirCustomer->id}/edit")->assertNotFound();
    $this->actingAs($this->admin)->get("/contracts/{$this->theirContract->id}")->assertNotFound();
    $this->actingAs($this->admin)->delete("/contracts/{$this->theirContract->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/contracts/{$this->mine->id}/documents/{$this->theirMedia->id}")->assertNotFound();
});

it('rejects a customer or asset of another tenant', function () {
    $this->actingAs($this->admin)->post('/contracts', [
        'customer_id' => $this->theirCustomer->id, 'contract_no' => 'X', 'title' => 'X', 'status' => 'active',
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'service_window' => '8x5', 'notify_days_before' => 30,
    ])->assertSessionHasErrors('customer_id');

    $this->actingAs($this->admin)->post('/assets', [
        'category_id' => createAssetCategory()->id, 'owner' => 'customer', 'customer_id' => $this->theirCustomer->id, 'name' => 'X', 'status' => 'in_use', 'location' => 'Stock',
    ])->assertSessionHasErrors('customer_id');

    $this->actingAs($this->admin)->post("/contracts/{$this->mine->id}/assets", ['asset_ids' => [$this->theirAsset->id]])
        ->assertSessionHasErrors('asset_ids');
    expect($this->mine->contractAssets()->count())->toBe(0);
});

it('hides other tenants from queries', function () {
    expect(Customer::pluck('name')->all())->toBe(['My customer'])
        ->and(Contract::count())->toBe(1)
        ->and(Media::count())->toBe(0);
});
