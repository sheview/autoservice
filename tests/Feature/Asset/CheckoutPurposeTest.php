<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * What each line of an issue/loan request and each purchase item is for (ItemPurpose): a purpose,
 * inside the company or for a customer and one of its projects, its own ticket, and proof of the
 * broken unit a replacement takes the place of (serial or photo).
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    Storage::fake('local');
    $this->admin = userWithRole('admin_company');
    $this->desk = userWithRole('helpdesk');
    $this->staff = userWithRole('user');
    $this->notebook = createAsset(createAssetCategory(), ['name' => 'Notebook', 'status' => Asset::STATUS_SPARE]);
    $this->ram = createPart(['code' => 'RAM8', 'name' => 'RAM 8GB', 'unit' => 'แผง'], stock: 5);
    $this->acme = createCustomer(['name' => 'Acme Hospital']);
    $this->contract = createContract($this->acme, ['contract_no' => 'MA-01']);
    $this->first = openTicket($this->desk, ['title' => 'PC slow']);
    $this->second = openTicket($this->desk, ['title' => 'Printer jam']);

    $this->line = fn (array $line = []) => $line + [
        'item_type' => 'asset', 'asset_id' => $this->notebook->id, 'qty' => 1, 'checkout_type' => 'issue',
        'purpose' => 'delivery', 'customer_name' => 'Acme Hospital', 'contract_id' => $this->contract->id,
    ];
    $this->send = fn (array $items) => $this->actingAs($this->desk)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'submit' => false, 'items' => $items,
    ]);
});

it('needs each line to say what it is for', function () {
    ($this->send)([($this->line)(['purpose' => null])])->assertSessionHasErrors('items.0.purpose');
    ($this->send)([($this->line)(['purpose' => 'cleaning'])])->assertSessionHasErrors('items.0.purpose');
    ($this->send)([($this->line)(['purpose' => 'other'])])->assertSessionHasErrors('items.0.purpose_other');
    ($this->send)([($this->line)(['purpose' => 'other', 'purpose_other' => 'Demo for sales'])])->assertSessionHasNoErrors();

    expect(CheckoutItem::sole()->only(['purpose', 'purpose_other', 'customer_name', 'contract_id']))
        ->toBe(['purpose' => 'other', 'purpose_other' => 'Demo for sales', 'customer_name' => 'Acme Hospital', 'contract_id' => $this->contract->id]);
});

it('needs a customer and its project, or use inside the company', function () {
    ($this->send)([($this->line)(['customer_name' => null, 'contract_id' => null])])->assertSessionHasErrors('items.0.customer_name');
    ($this->send)([($this->line)(['contract_id' => null])])->assertSessionHasErrors('items.0.contract_id');
    // A project of another customer is not one of theirs.
    $other = createContract(createCustomer(['name' => 'Beta Clinic']));
    ($this->send)([($this->line)(['contract_id' => $other->id])])->assertSessionHasErrors('items.0.contract_id');
    // A customer typed in has no project to pick.
    ($this->send)([($this->line)(['customer_name' => 'Walk-in shop', 'contract_id' => null])])->assertSessionHasNoErrors();
    ($this->send)([($this->line)(['for_internal' => true, 'customer_name' => null, 'contract_id' => null])])->assertSessionHasNoErrors();

    expect(CheckoutItem::orderBy('id')->get()->map->only(['for_internal', 'customer_name'])->all())->toBe([
        ['for_internal' => false, 'customer_name' => 'Walk-in shop'],
        ['for_internal' => true, 'customer_name' => null],
    ]);
});

it('needs the serial or a photo of the broken unit a replacement takes the place of', function () {
    ($this->send)([($this->line)(['purpose' => 'replace'])])->assertSessionHasErrors('items.0.replaced_serial');
    ($this->send)([($this->line)(['purpose' => 'customer_swap', 'replaced_serial' => 'SN-OLD-1'])])->assertSessionHasNoErrors();
    expect(CheckoutItem::sole()->replaced_serial)->toBe('SN-OLD-1');

    ($this->send)([($this->line)(['purpose' => 'replace', 'replaced_photo' => UploadedFile::fake()->image('broken.jpg')])])->assertSessionHasNoErrors();
    $request = CheckoutRequest::latest('id')->first();
    $line = $request->items()->sole();
    expect($line->replaced_photo_id)->toBe($request->getFirstMedia(CheckoutRequest::REPLACED_PHOTOS)->id);
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/replaced-photos/{$line->replaced_photo_id}")->assertOk();

    // The draft edited without a new photo keeps the one it has; taken off, it is gone.
    $keep = ($this->line)(['purpose' => 'replace', 'replaced_photo_id' => $line->replaced_photo_id]);
    $this->actingAs($this->desk)->put("/checkout-requests/{$request->ulid}", ['borrower_user_id' => $this->staff->id, 'items' => [$keep]])->assertSessionHasNoErrors();
    expect($request->items()->sole()->replaced_photo_id)->toBe($line->replaced_photo_id);
    $this->actingAs($this->desk)->put("/checkout-requests/{$request->ulid}", ['borrower_user_id' => $this->staff->id, 'items' => [($this->line)()]])->assertSessionHasNoErrors();
    expect($request->fresh()->getMedia(CheckoutRequest::REPLACED_PHOTOS))->toHaveCount(0);
});

it('issues each part line on its own ticket', function () {
    $part = fn (array $line) => ($this->line)(['item_type' => 'part', 'asset_id' => null, 'part_id' => $this->ram->id, 'qty' => 1, ...$line]);
    ($this->send)([$part([])])->assertSessionHasErrors('items.0.ticket_id');

    $this->actingAs($this->desk)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'submit' => true,
        'items' => [$part(['ticket_id' => $this->first->id]), $part(['ticket_id' => $this->second->id, 'qty' => 2])],
    ])->assertSessionHasNoErrors();
    $request = CheckoutRequest::sole();
    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();
    foreach ($request->items as $item) {
        $this->actingAs($this->desk)->post("/checkout-items/{$item->id}/fulfill", ['qty' => $item->qty_requested])->assertSessionHasNoErrors();
    }

    expect(StockMovement::where('type', StockMovement::TYPE_ISSUE)->orderBy('id')->pluck('ticket_id')->all())
        ->toBe([$this->first->id, $this->second->id]);
});

it('does not show the photo of another company\'s request', function () {
    ($this->send)([($this->line)(['purpose' => 'replace', 'replaced_photo' => UploadedFile::fake()->image('broken.jpg')])])->assertSessionHasNoErrors();
    $request = CheckoutRequest::sole();
    $media = $request->getFirstMedia(CheckoutRequest::REPLACED_PHOTOS)->id;

    $outsider = userWithRole('admin_company', tenant: createTenant('other'));
    $this->actingAs($outsider)->get("/checkout-requests/{$request->ulid}/replaced-photos/{$media}")->assertNotFound();
});

it('asks the same of each item of a purchase request', function () {
    $buyer = userWithRole('technician');
    $buy = fn (array $data) => $this->actingAs($buyer)->post('/purchase-requests', $data + [
        'item_name' => 'SSD 1TB', 'quantity' => 1, 'unit' => 'ลูก', 'links' => ['https://shop.example.com/ssd'], 'needed_by' => '2026-10-10',
        'purpose' => 'replace', 'customer_name' => 'Acme Hospital', 'contract_id' => $this->contract->id,
    ]);

    $buy([])->assertSessionHasErrors('replaced_serial');
    $buy(['contract_id' => null, 'replaced_serial' => 'SN-1'])->assertSessionHasErrors('contract_id');
    $buy(['extra_items' => [['item_name' => 'Fan', 'quantity' => 1, 'unit' => 'ตัว', 'links' => ['https://shop.example.com/fan'], 'purpose' => 'customer_swap', 'for_internal' => true]],
        'replaced_photo' => UploadedFile::fake()->image('broken.jpg')])
        ->assertSessionHasErrors('extra_items.0.replaced_serial');

    $buy([
        'replaced_photo' => UploadedFile::fake()->image('broken.jpg'), 'ticket_id' => ($job = openTicket($buyer, ['title' => 'Disk failing']))->id,
        'extra_items' => [['item_name' => 'Fan', 'quantity' => 1, 'unit' => 'ตัว', 'links' => ['https://shop.example.com/fan'], 'purpose' => 'project_spare', 'for_internal' => true]],
    ])->assertSessionHasNoErrors();

    [$ssd, $fan] = PurchaseRequest::orderBy('id')->get()->all();
    expect($ssd->only(['purpose', 'customer_name', 'contract_id', 'ticket_id']))
        ->toBe(['purpose' => 'replace', 'customer_name' => 'Acme Hospital', 'contract_id' => $this->contract->id, 'ticket_id' => $job->id])
        ->and($ssd->getFirstMedia(PurchaseRequest::REPLACED_PHOTO))->not->toBeNull()
        ->and($fan->only(['purpose', 'for_internal', 'customer_name', 'contract_id']))
        ->toBe(['purpose' => 'project_spare', 'for_internal' => true, 'customer_name' => null, 'contract_id' => null]);
    $this->actingAs($buyer)->get("/purchase-requests/{$ssd->ulid}/replaced-photo")->assertOk();
});
