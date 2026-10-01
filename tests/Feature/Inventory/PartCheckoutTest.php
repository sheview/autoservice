<?php

use App\Modules\Inventory\Actions\RequestPartCheckout;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Inventory\Models\StockMovement;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Somsak Tech']);
    $this->contract = createContract(createCustomer(['name' => 'Acme Hospital']), ['contract_no' => 'MA-2026-01', 'title' => 'Network MA']);
    $this->ram = createPart(['code' => 'RAM8', 'name' => 'RAM 8GB', 'unit' => 'แผง'], stock: 10);
    $this->ram->update(['contract_id' => $this->contract->id]);
    $this->ask = fn (array $data = [], $user = null) => $this->actingAs($user ?? $this->tech)->post("/parts/{$this->ram->id}/checkouts", $data + [
        'type' => 'issue', 'quantity' => 2, 'borrower_user_id' => $this->tech->id,
    ]);
});

it('keeps the MA contract on the part from the start', function () {
    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page->has('contracts', 1));
    $this->actingAs($this->admin)->post('/parts', [
        'code' => 'SSD1', 'name' => 'SSD 1TB', 'unit' => 'ลูก', 'contract_id' => $this->contract->id, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $ssd = Part::where('code', 'SSD1')->sole();
    expect($ssd->contract_id)->toBe($this->contract->id);
    $this->actingAs($this->admin)->get("/parts/{$ssd->id}")->assertInertia(fn (Assert $page) => $page
        ->where('part.contract.contract_no', 'MA-2026-01'));
});

it('issues parts: asked for, the stock goes out when approved, and they do not come back', function () {
    $this->actingAs($this->tech)->get("/parts/{$this->ram->id}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.available_quantity', 10)
        ->where('checkouts.default_contract_id', $this->contract->id)
        ->where('checkouts.can.request', true)
        ->where('checkouts.can.approve', false));

    ($this->ask)()->assertSessionHasNoErrors();
    $form = PartCheckout::sole();
    expect($form->only(['status', 'type', 'quantity', 'contract_id', 'borrower_name']))->toBe([
        'status' => 'pending', 'type' => 'issue', 'quantity' => 2, 'contract_id' => $this->contract->id, 'borrower_name' => 'Somsak Tech',
    ])->and($form->checkout_no)->toBe('PC-2569-00001')
        ->and($this->ram->fresh()->qty_on_hand)->toBe(10);

    // pending holds its quantity
    ($this->ask)(['quantity' => 9])->assertSessionHasErrors('quantity');

    $this->actingAs($this->tech)->post("/part-checkouts/{$form->ulid}/approve")->assertForbidden();
    $this->actingAs($this->admin)->post("/part-checkouts/{$form->ulid}/approve")->assertSessionHasNoErrors();

    expect($form->fresh()->status)->toBe('approved')
        ->and($this->ram->fresh()->qty_on_hand)->toBe(8)
        ->and(StockMovement::where('reference', $form->checkout_no)->sole()->only(['type', 'quantity']))->toBe(['type' => 'issue', 'quantity' => -2]);

    // used up: nothing to take back, and it is no longer open
    $this->actingAs($this->admin)->post("/part-checkouts/{$form->ulid}/return")->assertSessionHasErrors('checkout');
    $this->actingAs($this->admin)->get('/part-checkouts')->assertInertia(fn (Assert $page) => $page->has('checkouts.data', 0));
    $this->actingAs($this->admin)->get('/part-checkouts?status=all')->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/PartCheckouts/Index')
        ->where('checkouts.data.0.kind', 'part')
        ->where('checkouts.data.0.asset.asset_code', 'RAM8'));

    $this->actingAs($this->admin)->get("/part-checkouts/{$form->ulid}/print")->assertOk()->assertSee('ใบเบิกอะไหล่')->assertSee('RAM 8GB');
});

it('lends parts and takes them back into stock', function () {
    ($this->ask)(['type' => 'loan', 'quantity' => 3, 'due_on' => '2026-10-10', 'contract_id' => null, 'borrower_user_id' => null, 'borrower_name' => 'Contractor Lek'])
        ->assertSessionHasNoErrors();
    $form = PartCheckout::sole();
    expect($form->contract_id)->toBeNull();

    $this->actingAs($this->admin)->post("/part-checkouts/{$form->ulid}/approve");
    expect($this->ram->fresh()->qty_on_hand)->toBe(7);
    $this->actingAs($this->admin)->get('/part-checkouts')->assertInertia(fn (Assert $page) => $page->has('checkouts.data', 1));

    $this->actingAs($this->tech)->post("/part-checkouts/{$form->ulid}/return", ['note' => 'ครบ'])->assertSessionHasNoErrors();
    expect($form->fresh()->status)->toBe('returned')
        ->and($this->ram->fresh()->qty_on_hand)->toBe(10)
        ->and(StockMovement::where('reference', $form->checkout_no)->orderBy('id')->pluck('type')->all())->toBe(['loan', 'return']);
    $this->actingAs($this->admin)->get("/part-checkouts/{$form->ulid}/print")->assertOk()->assertSee('ใบยืมอะไหล่');
});

it('rejects with a reason, lets the asker cancel, and refuses an approval the stock can no longer cover', function () {
    ($this->ask)();
    $form = PartCheckout::sole();
    $this->actingAs($this->admin)->post("/part-checkouts/{$form->ulid}/reject")->assertSessionHasErrors('note');
    $this->actingAs($this->admin)->post("/part-checkouts/{$form->ulid}/reject", ['note' => 'ไม่จำเป็น'])->assertSessionHasNoErrors();
    expect($form->fresh()->status)->toBe('rejected')->and($this->ram->fresh()->qty_on_hand)->toBe(10);

    ($this->ask)();
    $mine = PartCheckout::latest('id')->first();
    $this->actingAs(userWithRole('technician'))->post("/part-checkouts/{$mine->ulid}/cancel")->assertForbidden();
    $this->actingAs($this->tech)->post("/part-checkouts/{$mine->ulid}/cancel")->assertSessionHasNoErrors();
    expect($mine->fresh()->status)->toBe('cancelled');

    // counted down by hand after the request: the approval cannot take what is not there
    ($this->ask)(['quantity' => 5]);
    $late = PartCheckout::latest('id')->first();
    $this->ram->forceFill(['qty_on_hand' => 1])->save();
    $this->actingAs($this->admin)->post("/part-checkouts/{$late->ulid}/approve")->assertSessionHasErrors('quantity');
    expect($late->fresh()->status)->toBe('pending');
});

it('is for staff who issue and lend, never another tenant', function () {
    ($this->ask)();
    $form = PartCheckout::sole();

    $user = userWithRole('user');
    $this->actingAs($user)->get('/part-checkouts')->assertForbidden();
    ($this->ask)([], $user)->assertForbidden();
    $this->actingAs($user)->post("/part-checkouts/{$form->ulid}/approve")->assertForbidden();

    $other = createTenant('other');
    $foreign = asTenant($other, function () {
        $part = createPart(stock: 5);
        $tech = userWithRole('technician');

        return app(RequestPartCheckout::class)->handle($part, ['type' => 'issue', 'quantity' => 1, 'borrower_name' => 'X'], $tech);
    });

    $this->actingAs($this->admin)->post("/part-checkouts/{$foreign->ulid}/approve")->assertNotFound();
    $this->actingAs($this->admin)->get('/part-checkouts?status=all')->assertInertia(fn (Assert $page) => $page->has('checkouts.data', 1));
});

it('counts parts in the summaries by person and by project', function () {
    ($this->ask)();
    $this->actingAs($this->admin)->post('/part-checkouts/'.PartCheckout::sole()->ulid.'/approve');

    $this->actingAs($this->admin)->get("/summary/projects/{$this->contract->id}")->assertInertia(fn (Assert $page) => $page
        ->where('totals.issues', 1)
        ->where('partCheckouts.data.0.asset.asset_code', 'RAM8'));
    $this->actingAs($this->admin)->get("/summary/people/view?user={$this->tech->id}")->assertInertia(fn (Assert $page) => $page
        ->where('totals.issues', 1)
        ->has('partCheckouts.data', 1));
});
