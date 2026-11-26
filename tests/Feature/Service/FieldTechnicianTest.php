<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\FieldTechnician;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\CompanyCodes;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/*
 * The register of outside technicians: they sign up through the company's link without an
 * account and wait for staff to approve them; staff find the approved ones by province and kind
 * of work and call them, or pick one when making a ticket's link. Another company sees the
 * approved ones only through a share (field-technicians.view), and changes nothing.
 */

beforeEach(function () {
    CompanyCodes::forget();
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    Feature::for($this->tenant)->activate(Modules::feature('field_technicians'));
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Desk One']);

    $this->signUp = fn (array $data = []) => $this->post('/t/001/join-technician', $data + [
        'name' => 'ช่างสมชาย ใจดี',
        'company' => 'ร้านคอมขอนแก่น',
        'phone' => '081-234-5678',
        'line_id' => 'somchai.kk',
        'provinces' => ['ขอนแก่น', 'มหาสารคาม'],
        'skills' => ['pc', 'printer'],
        'note' => 'ซ่อมคอม 5 ปี',
        'consent' => '1',
    ]);
    $this->registered = function (array $data = []) {
        ($this->signUp)($data)->assertSessionHasNoErrors();

        return FieldTechnician::query()->latest('id')->first();
    };
});

it('lets a technician sign up through the link without an account, waiting for approval', function () {
    $this->get('/t/001/join-technician')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Service/FieldTechnicianSignup')->where('company', $this->tenant->name)->has('provinces', 77));

    ($this->signUp)()->assertSessionHasNoErrors()->assertSessionHas('success');

    $technician = FieldTechnician::query()->with('tags')->first();
    expect($technician->only(['name', 'company', 'phone', 'phone_key', 'line_id', 'status']))->toBe([
        'name' => 'ช่างสมชาย ใจดี', 'company' => 'ร้านคอมขอนแก่น', 'phone' => '081-234-5678', 'phone_key' => '0812345678',
        'line_id' => 'somchai.kk', 'status' => 'pending',
    ])
        ->and($technician->tagValues('province'))->toEqualCanonicalizing(['ขอนแก่น', 'มหาสารคาม'])
        ->and($technician->tagValues('skill'))->toEqualCanonicalizing(['pc', 'printer'])
        ->and($technician->tenant_id)->toBe($this->tenant->id)
        ->and($technician->consented_at)->not->toBeNull();
});

it('takes one sign-up per phone number and checks what was sent', function () {
    ($this->registered)();

    ($this->signUp)(['phone' => '0812345678', 'name' => 'อีกคน'])->assertSessionHasErrors('phone');
    ($this->signUp)(['phone' => '0899999999', 'consent' => null])->assertSessionHasErrors('consent');
    ($this->signUp)(['phone' => '0899999999', 'provinces' => ['Gotham']])->assertSessionHasErrors('provinces.0');
    ($this->signUp)(['phone' => '0899999999', 'provinces' => []])->assertSessionHasErrors('provinces');
    ($this->signUp)(['phone' => '0899999999', 'skills' => ['magic']])->assertSessionHasErrors('skills.0');
    ($this->signUp)(['phone' => '022'])->assertSessionHasErrors('phone');

    expect(FieldTechnician::count())->toBe(1);
});

it('has no sign-up page for a company that keeps no register', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('field_technicians'));

    $this->get('/t/001/join-technician')->assertInertia(fn (Assert $page) => $page->where('company', null));
    ($this->signUp)()->assertNotFound();
    $this->get('/t/999/join-technician')->assertInertia(fn (Assert $page) => $page->where('company', null));
    expect(FieldTechnician::count())->toBe(0);
});

it('lets staff approve, find, correct and remove technicians, and gives them the link to send', function () {
    $somchai = ($this->registered)();
    $north = ($this->registered)(['name' => 'ช่างเหนือ', 'phone' => '0850000000', 'line_id' => null, 'provinces' => ['เชียงใหม่'], 'skills' => ['network']]);

    $this->actingAs($this->helpdesk)->get('/field-technicians')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/FieldTechnicians/Index')
        ->where('can.manage', true)
        ->where('pending', 2)
        ->where('signupUrl', 'http://localhost/t/001/join-technician')
        ->where('sources.0.own', true)
        ->has('technicians.data', 2));

    $this->actingAs($this->helpdesk)->post("/field-technicians/{$somchai->ulid}/review", ['approve' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->helpdesk)->post("/field-technicians/{$north->ulid}/review", ['approve' => false])->assertSessionHasNoErrors();
    expect($somchai->fresh()->only(['status', 'reviewed_by_name']))->toBe(['status' => 'approved', 'reviewed_by_name' => 'Desk One'])
        ->and($north->fresh()->status)->toBe('rejected');

    // Found by province, by kind of work, by status and by words.
    $find = fn (string $query) => $this->actingAs($this->helpdesk)->get("/field-technicians?{$query}");
    $find('province=ขอนแก่น')->assertInertia(fn (Assert $page) => $page->has('technicians.data', 1)->where('technicians.data.0.name', 'ช่างสมชาย ใจดี'));
    $find('skill=network')->assertInertia(fn (Assert $page) => $page->has('technicians.data', 1)->where('technicians.data.0.name', 'ช่างเหนือ'));
    $find('status=approved')->assertInertia(fn (Assert $page) => $page->has('technicians.data', 1));
    $find('search=somchai')->assertInertia(fn (Assert $page) => $page->has('technicians.data', 1));
    $find('search=มหาสารคาม')->assertInertia(fn (Assert $page) => $page->has('technicians.data', 1));

    $this->actingAs($this->helpdesk)->put("/field-technicians/{$somchai->ulid}", [
        'name' => 'สมชาย ใจดี', 'company' => null, 'phone' => '081 234 5678', 'provinces' => ['ขอนแก่น'], 'skills' => ['cctv'],
    ])->assertSessionHasNoErrors();
    $somchai->refresh()->load('tags');
    expect($somchai->only(['name', 'company', 'status']))->toBe(['name' => 'สมชาย ใจดี', 'company' => null, 'status' => 'approved'])
        ->and($somchai->tagValues('skill'))->toBe(['cctv']);
    // Another technician's phone is not taken over.
    $this->actingAs($this->helpdesk)->put("/field-technicians/{$somchai->ulid}", [
        'name' => 'x', 'phone' => '0850000000', 'provinces' => ['ขอนแก่น'], 'skills' => ['pc'],
    ])->assertSessionHasErrors('phone');

    $this->actingAs($this->helpdesk)->delete("/field-technicians/{$north->ulid}")->assertSessionHasNoErrors();
    expect(FieldTechnician::count())->toBe(1)->and(FieldTechnician::withTrashed()->count())->toBe(2);
    // The number is free again once removed.
    ($this->signUp)(['phone' => '0850000000'])->assertSessionHasNoErrors();
});

it('keeps the register from those without the right', function () {
    $technician = ($this->registered)();

    $this->actingAs(userWithRole('technician'))->get('/field-technicians')->assertForbidden();
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get('/field-technicians')->assertForbidden();
    $this->actingAs(userWithRole('technician'))->post("/field-technicians/{$technician->ulid}/review", ['approve' => true])->assertForbidden();
    expect($technician->fresh()->status)->toBe('pending');
});

it('shows another company the approved technicians only through a share, to read and call', function () {
    $approved = ($this->registered)();
    $approved->forceFill(['status' => 'approved'])->save();
    ($this->registered)(['name' => 'ยังไม่อนุมัติ', 'phone' => '0850000000']);

    $sister = createTenant('sister');
    $theirDesk = userWithRole('helpdesk', ['name' => 'Sister Desk'], $sister);

    // No share: nothing of ours, and no menu.
    asTenant($sister, fn () => $this->actingAs($theirDesk)->get('/field-technicians')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sources', [])->where('technicians', null)));
    asTenant($sister, fn () => $this->actingAs($theirDesk)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->doesntContain('title', 'ทะเบียนช่างนอก'))));

    // We share our register with them.
    $this->actingAs(createSuperadmin())->put("/platform/tenants/{$this->tenant->ulid}/shares/{$sister->ulid}", [
        'abilities' => ['field-technicians.view'], 'roles' => ['helpdesk'], 'activate' => true,
    ])->assertSessionHasNoErrors();

    asTenant($sister, fn () => $this->actingAs($theirDesk)->get('/field-technicians')->assertInertia(fn (Assert $page) => $page
        ->where('sources.0.id', $this->tenant->id)->where('sources.0.own', false)
        ->where('can.manage', false)->where('signupUrl', null)
        ->has('technicians.data', 1)->where('technicians.data.0.name', 'ช่างสมชาย ใจดี')));
    asTenant($sister, fn () => $this->actingAs($theirDesk)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->contains('title', 'ทะเบียนช่างนอก'))));

    // They change nothing of ours: not even found from their company.
    asTenant($sister, fn () => $this->actingAs($theirDesk)->post("/field-technicians/{$approved->ulid}/review", ['approve' => false])->assertNotFound());
    Feature::for($sister)->activate(Modules::feature('field_technicians'));
    asTenant($sister, fn () => $this->actingAs($theirDesk)->post("/field-technicians/{$approved->ulid}/review", ['approve' => false])->assertNotFound());
    expect($approved->fresh()->status)->toBe('approved');
});

it('offers approved technicians of the ticket\'s province first when making an outside technician link', function () {
    $khonKaen = ($this->registered)();
    $north = ($this->registered)(['name' => 'ช่างเหนือ', 'phone' => '0850000000', 'provinces' => ['เชียงใหม่']]);
    ($this->registered)(['name' => 'รออนุมัติ', 'phone' => '0860000000']);
    FieldTechnician::query()->whereKey([$khonKaen->id, $north->id])->update(['status' => 'approved']);

    $branch = Branch::create(['code' => 'KK', 'name' => 'สาขาขอนแก่น', 'province' => 'ขอนแก่น']);
    $ticket = openTicket($this->helpdesk, ['title' => 'PC down']);
    $ticket->forceFill(['branch_id' => $branch->id])->save();

    $this->actingAs($this->helpdesk)->getJson("/tickets/{$ticket->ulid}/field-technicians")->assertOk()
        ->assertJsonPath('province', 'ขอนแก่น')
        ->assertJsonCount(2, 'technicians')
        ->assertJsonPath('technicians.0.name', 'ช่างสมชาย ใจดี')
        ->assertJsonPath('technicians.0.in_province', true)
        ->assertJsonPath('technicians.1.in_province', false)
        ->assertJsonPath('technicians.0.source', null);
    $this->actingAs($this->helpdesk)->getJson("/tickets/{$ticket->ulid}/field-technicians?search=เหนือ")
        ->assertJsonCount(1, 'technicians')->assertJsonPath('technicians.0.name', 'ช่างเหนือ');

    // A company with no register to read gets no picker.
    Feature::for($this->tenant)->deactivate(Modules::feature('field_technicians'));
    $this->actingAs($this->helpdesk)->getJson("/tickets/{$ticket->ulid}/field-technicians")->assertNotFound();
});

it('reads the province of a free-typed place', function () {
    expect(App\Modules\Service\Support\ThaiProvinces::find('สาขาขอนแก่น'))->toBe('ขอนแก่น')
        ->and(App\Modules\Service\Support\ThaiProvinces::find('กรุงเทพฯ'))->toBe('กรุงเทพมหานคร')
        ->and(App\Modules\Service\Support\ThaiProvinces::find('จ.นครราชสีมา'))->toBe('นครราชสีมา')
        ->and(App\Modules\Service\Support\ThaiProvinces::find('Head office'))->toBeNull();
});
