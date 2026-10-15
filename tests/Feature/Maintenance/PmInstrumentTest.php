<?php

use App\Modules\Maintenance\Models\PmInstrument;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-06-10 09:00');
    $this->admin = userWithRole('admin_company');
});

it('keeps a register of measuring instruments with their calibration due dates', function () {
    $this->actingAs($this->admin)->post('/pm-instruments', ['name' => 'Fluke 117', 'serial_no' => 'F-1', 'calibration_due_at' => '2026-06-01'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/pm-instruments', ['name' => 'Clamp 376', 'serial_no' => 'C-1', 'calibration_due_at' => '2026-12-31'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/pm-instruments', ['name' => 'Again', 'serial_no' => 'f-1'])->assertSessionHasErrors('serial_no');

    $this->actingAs($this->admin)->get('/pm-instruments?sort=name')->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Instruments/Index')
        ->where('instruments.total', 2)
        ->where('instruments.data.0.name', 'Clamp 376')
        ->where('instruments.data.0.calibration', 'ok')
        ->where('instruments.data.1.calibration', 'overdue'));
    $this->actingAs($this->admin)->get('/pm-instruments?due=1')->assertInertia(fn (Assert $page) => $page->where('instruments.total', 1));
    $this->actingAs($this->admin)->get('/pm-instruments?search=376')->assertInertia(fn (Assert $page) => $page->where('instruments.total', 1));

    $fluke = PmInstrument::where('serial_no', 'F-1')->sole();
    $this->actingAs($this->admin)->put("/pm-instruments/{$fluke->id}", ['name' => 'Fluke 117', 'serial_no' => 'F-1', 'calibration_due_at' => '2027-06-01'])
        ->assertSessionHasNoErrors();
    expect($fluke->fresh()->calibration_due_at->toDateString())->toBe('2027-06-01');

    $this->actingAs($this->admin)->delete("/pm-instruments/{$fluke->id}")->assertSessionHasNoErrors();
    expect(PmInstrument::count())->toBe(1);
    // a deleted serial is free again
    $this->actingAs($this->admin)->post('/pm-instruments', ['name' => 'Fluke new', 'serial_no' => 'F-1'])->assertSessionHasNoErrors();
});

it('lets only PM settings managers change the register, and keeps it per company', function () {
    $tech = userWithRole('technician');
    $this->actingAs($tech)->get('/pm-instruments')->assertForbidden();
    $this->actingAs($tech)->post('/pm-instruments', ['name' => 'x', 'serial_no' => 'x'])->assertForbidden();

    $theirs = asTenant(createTenant('other'), fn () => createPmInstrument(['serial_no' => 'SAME']));
    $this->actingAs($this->admin)->post('/pm-instruments', ['name' => 'Mine', 'serial_no' => 'SAME'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/pm-instruments/{$theirs->id}", ['name' => 'x', 'serial_no' => 'x'])->assertNotFound();
    $this->actingAs($this->admin)->delete("/pm-instruments/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->admin)->get('/pm-instruments')->assertInertia(fn (Assert $page) => $page->where('instruments.total', 1));
});
