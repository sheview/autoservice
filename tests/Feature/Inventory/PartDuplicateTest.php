<?php

use App\Modules\Inventory\Models\Part;

/*
 * The same part typed twice (FindDuplicateParts): the same name or part number, ignoring case,
 * spaces and punctuation, is refused with the part it repeats; the form asks while typing.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->toner = createPart(['code' => 'PT-00021', 'name' => 'Toner HP 59A', 'part_number' => 'CF259A', 'unit' => 'ตลับ'], stock: 3);
    $this->create = fn (array $data) => $this->actingAs($this->admin)->post('/parts', $data + ['unit' => 'ชิ้น']);
});

it('refuses a part with the name or part number of one already there, naming it', function () {
    ($this->create)(['name' => 'toner hp-59a'])
        ->assertSessionHasErrors(['name' => 'ชื่อซ้ำกับอะไหล่ PT-00021 Toner HP 59A']);
    ($this->create)(['name' => 'Toner ตลับใหม่', 'part_number' => 'cf-259a'])
        ->assertSessionHasErrors(['part_number' => 'Part number ซ้ำกับอะไหล่ PT-00021 Toner HP 59A']);
    ($this->create)(['name' => 'Toner HP 26A', 'part_number' => 'CF226A'])->assertSessionHasNoErrors();

    expect(Part::count())->toBe(2);
});

it('lets a part keep its own name and part number when edited', function () {
    $this->actingAs($this->admin)->put("/parts/{$this->toner->id}", [
        'code' => 'PT-00021', 'name' => 'Toner HP 59A', 'part_number' => 'CF259A', 'unit' => 'ตลับ', 'notes' => 'ใหม่',
    ])->assertSessionHasNoErrors();

    $other = createPart(['code' => 'PT-00022', 'name' => 'Drum']);
    $this->actingAs($this->admin)->put("/parts/{$other->id}", ['code' => 'PT-00022', 'name' => 'TONER HP 59A', 'unit' => 'ชิ้น'])
        ->assertSessionHasErrors('name');
});

it('shows the form the parts it would repeat, with a link to each', function () {
    $this->actingAs($this->admin)->getJson('/parts/duplicates?name=Toner%20HP%2059A&part_number=X')
        ->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.code', 'PT-00021')->assertJsonPath('0.qty_on_hand', 3)->assertJsonPath('0.reasons', ['name'])
        ->assertJsonPath('0.url', route('inventory.parts.show', $this->toner->id));
    $this->actingAs($this->admin)->getJson("/parts/duplicates?name=Toner%20HP%2059A&except={$this->toner->id}")->assertJsonCount(0);
    $this->actingAs($this->admin)->getJson('/parts/duplicates')->assertJsonCount(0);
    $this->actingAs(userWithRole('user'))->getJson('/parts/duplicates?name=Toner')->assertForbidden();
});

it('does not count a part of another company', function () {
    asTenant(createTenant('other'), fn () => createPart(['code' => 'X1', 'name' => 'Mouse Logitech', 'part_number' => 'M90']));

    ($this->create)(['name' => 'Mouse Logitech', 'part_number' => 'M90'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->getJson('/parts/duplicates?name=Mouse%20Logitech')->assertJsonCount(1);
});
