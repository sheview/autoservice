<?php

use App\Modules\Document\Models\Media;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A part created with its first stock (CreatePart): one receipt in the same transaction, by
 * count or by serial numbers; and the photo slots answering the page's fetch uploads in JSON.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Store keeper']);
    $this->create = fn (array $data = []) => $this->actingAs($this->admin)->post('/parts', $data + [
        'code' => 'TN-1', 'name' => 'Toner 59A', 'unit' => 'ตลับ',
    ]);
});

it('receives the initial quantity with the part: stock as typed and one receipt', function () {
    ($this->create)(['initial_qty' => 5, 'unit_cost' => '1250.50'])->assertSessionHasNoErrors();

    $part = Part::sole();
    $movement = StockMovement::sole();
    expect($part->qty_on_hand)->toBe(5)
        ->and($movement->only(['part_id', 'type', 'quantity', 'balance_after', 'unit_cost', 'note', 'user_id', 'user_name']))->toBe([
            'part_id' => $part->id, 'type' => 'receive', 'quantity' => 5, 'balance_after' => 5, 'unit_cost' => 125050,
            'note' => 'รับเข้าตอนสร้างรายการ', 'user_id' => $this->admin->id, 'user_name' => 'Store keeper',
        ]);
    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page->where('canReceive', true));
});

it('creates no receipt when the initial quantity is left at 0', function () {
    ($this->create)(['initial_qty' => 0])->assertSessionHasNoErrors();

    expect(Part::sole()->qty_on_hand)->toBe(0)->and(StockMovement::count())->toBe(0);
});

it('receives a part tracked by serial by its serial numbers, one piece each', function () {
    $category = createAssetCategory(['name' => 'SSD/HDD', 'requires_serial' => true]);

    ($this->create)(['asset_category_id' => $category->id, 'initial_serials' => ['SN-1', '', ' SN-2 ']])->assertSessionHasNoErrors();

    $part = Part::sole();
    expect($part->only(['track_serial', 'qty_on_hand']))->toBe(['track_serial' => true, 'qty_on_hand' => 2])
        ->and(PartUnit::orderBy('id')->pluck('serial_number')->all())->toBe(['SN-1', 'SN-2'])
        ->and(StockMovement::sole()->only(['quantity', 'note']))->toBe(['quantity' => 2, 'note' => 'รับเข้าตอนสร้างรายการ']);
});

it('keeps neither the part nor the receipt when the receipt is refused', function () {
    $category = createAssetCategory(['name' => 'SSD/HDD', 'requires_serial' => true]);

    // A tracked part given a count: serial numbers are needed.
    ($this->create)(['asset_category_id' => $category->id, 'initial_qty' => 3])->assertSessionHasErrors('initial_serials');
    // The same serial twice.
    ($this->create)(['asset_category_id' => $category->id, 'initial_serials' => ['SN-1', 'sn-1']])->assertSessionHasErrors();
    // Serials for a part counted only.
    ($this->create)(['initial_serials' => ['SN-1']])->assertSessionHasErrors('initial_qty');

    expect(Part::withTrashed()->count())->toBe(0)->and(StockMovement::count())->toBe(0)->and(PartUnit::count())->toBe(0);
});

it('takes the initial stock only on a new part, and only from whoever may enter receipts', function () {
    ($this->create)()->assertSessionHasNoErrors();
    $part = Part::sole();

    $this->actingAs($this->admin)->put("/parts/{$part->id}", ['code' => 'TN-1', 'name' => 'Toner', 'unit' => 'ตลับ', 'initial_qty' => 4])
        ->assertSessionHasErrors('initial_qty');

    // May create parts but not enter stock movements.
    grantTo('user', ['parts.view', 'parts.create']);
    $staff = userWithRole('user');
    expect($staff->can('stock-movements.create'))->toBeFalse();
    $this->actingAs($staff)->post('/parts', ['code' => 'TN-2', 'name' => 'Toner', 'unit' => 'ตลับ', 'initial_qty' => 4])
        ->assertSessionHasErrors('initial_qty');
    $this->actingAs($staff)->get('/parts/create')->assertInertia(fn (Assert $page) => $page->where('canReceive', false));

    expect($part->fresh()->qty_on_hand)->toBe(0)->and(StockMovement::count())->toBe(0);
});

it('answers a photo sent by the page in JSON: saved, or why not', function () {
    Storage::fake('local');
    ($this->create)()->assertSessionHasNoErrors();
    $part = Part::sole();
    $json = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    $this->actingAs($this->admin)->post("/parts/{$part->id}/photos/0", ['photo' => UploadedFile::fake()->image('a.jpg', 1600, 1200)], $json)
        ->assertOk()->assertJson(['message' => 'บันทึกรูปภาพเรียบร้อยแล้ว']);
    $this->actingAs($this->admin)->post("/parts/{$part->id}/photos/1", ['photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], $json)
        ->assertUnprocessable()->assertJsonValidationErrors('photo');

    expect(Media::count())->toBe(1);
});

it('says in Thai when a request is larger than the server takes', function () {
    $response = $this->actingAs($this->admin)->call('POST', '/parts', server: ['CONTENT_LENGTH' => (string) (1024 ** 4)]);

    $response->assertStatus(413);
    expect($response->getContent())->toContain('ไฟล์ที่ส่งมีขนาดใหญ่เกินกว่าที่ server รับได้');
    $this->actingAs($this->admin)->call('POST', '/parts', server: ['CONTENT_LENGTH' => (string) (1024 ** 4), 'HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(413)->assertJsonPath('message', fn (string $message) => str_contains($message, 'ข้อมูลยังไม่ถูกบันทึก'));
    expect(Part::count())->toBe(0);
});
