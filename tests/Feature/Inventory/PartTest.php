<?php

use App\Modules\Inventory\Models\Part;
use App\Modules\Platform\Support\Modules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('creates a part with an upper-case code, cost in satang and no stock', function () {
    $this->actingAs($this->admin)->post('/parts', [
        'code' => 'ram-ddr4-8g', 'name' => 'RAM DDR4 8GB', 'brand' => 'Kingston', 'part_number' => 'KVR26N19S8/8',
        'unit' => 'ชิ้น', 'min_qty' => 2, 'unit_cost' => '1250.50',
    ])->assertSessionHasNoErrors();

    $part = Part::first();
    expect($part->only(['code', 'name', 'unit', 'min_qty', 'unit_cost', 'qty_on_hand', 'is_active', 'tenant_id']))->toBe([
        'code' => 'RAM-DDR4-8G', 'name' => 'RAM DDR4 8GB', 'unit' => 'ชิ้น', 'min_qty' => 2, 'unit_cost' => 125050,
        'qty_on_hand' => 0, 'is_active' => true, 'tenant_id' => $this->tenant->id,
    ]);

    $this->actingAs($this->admin)->get("/parts/{$part->id}")
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Parts/Show')
            ->where('part.unit_cost', '1250.50')
            ->where('movements.total', 0)
            ->where('movementTypes', ['receive', 'issue', 'adjust']));
});

it('rejects a duplicate code ignoring case and bad numbers', function () {
    createPart(['code' => 'HDD-1TB']);

    $this->actingAs($this->admin)->post('/parts', ['code' => 'hdd-1tb', 'name' => 'X', 'unit' => 'pcs', 'min_qty' => -1, 'unit_cost' => '1.234'])
        ->assertSessionHasErrors(['code', 'min_qty', 'unit_cost']);
    $this->actingAs($this->admin)->post('/parts', ['code' => 'bad code', 'name' => 'X'])
        ->assertSessionHasErrors(['code', 'unit']);
});

it('updates a part without touching its stock', function () {
    $part = createPart(['code' => 'FAN', 'name' => 'Fan'], stock: 5);

    $this->actingAs($this->admin)->put("/parts/{$part->id}", [
        'code' => 'FAN', 'name' => 'Case fan 120mm', 'unit' => 'pcs', 'min_qty' => 3, 'is_active' => false, 'qty_on_hand' => 99,
    ])->assertSessionHasNoErrors();

    expect($part->fresh()->only(['name', 'min_qty', 'is_active', 'qty_on_hand']))
        ->toBe(['name' => 'Case fan 120mm', 'min_qty' => 3, 'is_active' => false, 'qty_on_hand' => 5]);
});

it('lists parts with search, status and stock filters, and sort', function () {
    createPart(['code' => 'RAM', 'name' => 'Memory', 'brand' => 'Kingston', 'min_qty' => 5], stock: 3);   // low
    createPart(['code' => 'SSD', 'name' => 'Solid state drive', 'min_qty' => 1], stock: 10);              // fine
    createPart(['code' => 'PSU', 'name' => 'Power supply', 'is_active' => false]);                        // out

    $get = fn (string $query) => $this->actingAs($this->admin)->get("/parts?{$query}");

    $get('')->assertInertia(fn (Assert $page) => $page->component('Inventory/Parts/Index')
        ->where('parts.total', 3)
        ->where('parts.data.0.code', 'PSU')
        ->where('parts.data.1.low', true)
        ->where('can.create', true));
    $get('search=kingston')->assertInertia(fn (Assert $page) => $page->where('parts.total', 1)->where('parts.data.0.code', 'RAM'));
    $get('status=inactive')->assertInertia(fn (Assert $page) => $page->where('parts.total', 1)->where('parts.data.0.code', 'PSU'));
    $get('stock=low')->assertInertia(fn (Assert $page) => $page->where('parts.total', 1)->where('parts.data.0.code', 'RAM'));
    $get('stock=out')->assertInertia(fn (Assert $page) => $page->where('parts.total', 1)->where('parts.data.0.code', 'PSU'));
    $get('sort=qty_on_hand&direction=desc')->assertInertia(fn (Assert $page) => $page->where('parts.data.0.code', 'SSD'));
    // an unknown sort column falls back to the default
    $get('sort=tenant_id')->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'code'));
});

it('deletes only a part without stock', function () {
    $stocked = createPart(['name' => 'Stocked'], stock: 1);
    $empty = createPart(['name' => 'Empty']);

    $this->actingAs($this->admin)->delete("/parts/{$stocked->id}")->assertSessionHasErrors('part');
    $this->actingAs($this->admin)->delete("/parts/{$empty->id}")->assertRedirect('/parts')->assertSessionHasNoErrors();

    expect(Part::pluck('name')->all())->toBe(['Stocked'])
        ->and(Part::withTrashed()->count())->toBe(2);
});

it('lets technicians and helpdesk view parts but not change them, and hides them from others', function () {
    $part = createPart();
    $payload = ['code' => 'X', 'name' => 'X', 'unit' => 'pcs'];

    foreach (['technician', 'helpdesk'] as $role) {
        $user = userWithRole($role);
        $this->actingAs($user)->get('/parts')->assertOk();
        $this->actingAs($user)->get("/parts/{$part->id}")->assertOk();
        $this->actingAs($user)->post('/parts', $payload)->assertForbidden();
        $this->actingAs($user)->put("/parts/{$part->id}", $payload)->assertForbidden();
        $this->actingAs($user)->delete("/parts/{$part->id}")->assertForbidden();
    }

    $this->actingAs(userWithRole('user'))->get('/parts')->assertForbidden();

    $client = userWithRole('customer', ['customer_id' => createCustomer()->id]);
    $this->actingAs($client)->get('/parts')->assertForbidden();
    $this->actingAs($client)->get("/parts/{$part->id}")->assertForbidden();
});

it('hides the module when it is switched off', function () {
    $part = createPart();
    Feature::for($this->tenant)->deactivate(Modules::feature('inventory'));

    $this->actingAs($this->admin)->get('/parts')->assertNotFound();
    $this->actingAs($this->admin)->get("/parts/{$part->id}")->assertNotFound();
    $this->actingAs($this->admin)->get('/stock-movements')->assertNotFound();
});
