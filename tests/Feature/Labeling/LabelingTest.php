<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Labeling\Models\AssetLabelPrint;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->tech = userWithRole('technician');
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'Switch']);
    $this->a1 = createAsset($category, ['name' => 'Core switch', 'customer_id' => $this->customer->id]);
    $this->a2 = createAsset($category, ['name' => 'Edge switch']);
});

it('lists assets to label with search, filters and when they were last printed', function () {
    $this->actingAs($this->tech)->get('/labels')
        ->assertInertia(fn (Assert $page) => $page->component('Labeling/Index')
            ->where('assets.total', 2)
            ->where('assets.data.0.asset_code', $this->a1->asset_code)
            ->where('assets.data.0.customer', 'Acme')
            ->where('assets.data.0.last_printed_at', null));

    $this->actingAs($this->tech)->post('/labels/print', ['assets' => [$this->a1->ulid], 'template' => 'a4_3x8'])
        ->assertSessionHasNoErrors();

    expect(AssetLabelPrint::first())->asset_id->toBe($this->a1->id)->template->toBe('a4_3x8')->user_id->toBe($this->tech->id);

    $this->actingAs($this->tech)->get('/labels?printed=no')
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.ulid', $this->a2->ulid));
    $this->actingAs($this->tech)->get('/labels?printed=yes')
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.last_printed_at', fn ($at) => $at !== null));
    $this->actingAs($this->tech)->get('/labels?search=edge')
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1));
    $this->actingAs($this->tech)->get("/labels?customer_id={$this->customer->id}&sort=name&direction=desc")
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1));
});

it('renders the print page with a QR code that opens the scan page', function () {
    $this->actingAs($this->tech)->get("/labels/print?assets={$this->a2->ulid},{$this->a1->ulid}&template=roll_40x25")
        ->assertInertia(fn (Assert $page) => $page->component('Labeling/Print')
            ->where('template', 'roll_40x25')
            ->has('labels', 2)
            // in the order asked for
            ->where('labels.0.ulid', $this->a2->ulid)
            ->where('labels.1.customer', 'Acme')
            ->where('labels.0.qr', fn ($svg) => str_starts_with($svg, '<svg') && str_contains($svg, 'viewBox')));

    // a bad link (opened in a new tab) is a 404, not a redirect back to itself
    $this->actingAs($this->tech)->get('/labels/print?assets=')->assertNotFound();
    $this->actingAs($this->tech)->get("/labels/print?assets={$this->a1->ulid}&template=huge")->assertNotFound();
    $this->actingAs($this->tech)->get('/labels/print?assets='.implode(',', array_fill(0, 301, $this->a1->ulid)))->assertNotFound();
});

it('only labels assets the user may see', function () {
    $branch = Branch::create(['code' => 'CNX', 'name' => 'Chiang Mai']);
    $elsewhere = createAsset(createAssetCategory(), ['branch_id' => $branch->id]);
    $myBranch = Branch::create(['code' => 'BKK', 'name' => 'Bangkok']);
    $tech = userWithRole('technician', ['branch_id' => $myBranch->id]);

    $this->actingAs($tech)->get("/labels/print?assets={$elsewhere->ulid}")->assertNotFound();
    $this->actingAs($tech)->post('/labels/print', ['assets' => [$elsewhere->ulid, $this->a1->ulid], 'template' => 'roll_50x30']);
    expect(AssetLabelPrint::pluck('asset_id')->all())->toBe([$this->a1->id]);

    // office users cannot print labels
    $this->actingAs(userWithRole('user'))->get('/labels')->assertForbidden();
});

it('opens the scan page after sign-in, for whoever may see the asset', function () {
    $this->get("/a/{$this->a1->ulid}")->assertRedirect('/login');

    $this->actingAs($this->tech)->get("/a/{$this->a1->ulid}")
        ->assertInertia(fn (Assert $page) => $page->component('Labeling/Scan')
            ->where('asset.asset_code', $this->a1->asset_code)
            ->where('asset.customer', 'Acme')
            ->where('tickets', [])
            // technicians may open tickets (tickets.create)
            ->where('can.openTicket', true));

    // an office user (assets.view own) only reaches what they hold
    $this->actingAs(userWithRole('user'))->get("/a/{$this->a1->ulid}")->assertNotFound();

    $helpdesk = userWithRole('helpdesk');
    $ticket = openTicket($helpdesk, ['asset_id' => $this->a1->id, 'customer_id' => $this->customer->id]);
    $this->actingAs($helpdesk)->get("/a/{$this->a1->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('tickets.0.ticket_no', $ticket->ticket_no)->where('can.openTicket', true));

    // a customer account: its own assets only
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get("/a/{$this->a1->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.openTicket', true));
    $this->actingAs($client)->get("/a/{$this->a2->ulid}")->assertNotFound();
    $this->actingAs($client)->get('/labels')->assertForbidden();

    $this->actingAs($this->tech)->get('/a/NOTAULID')->assertNotFound();
});

it('keeps labels and scans per tenant, and scanning works with labeling switched off', function () {
    $this->actingAs($this->tech)->post('/labels/print', ['assets' => [$this->a1->ulid], 'template' => 'roll_50x30']);

    $other = createTenant('other');
    $theirAsset = asTenant($other, fn () => createAsset(createAssetCategory()));

    $this->actingAs($this->tech)->get("/a/{$theirAsset->ulid}")->assertNotFound();
    $this->actingAs($this->tech)->get("/labels/print?assets={$theirAsset->ulid}")->assertNotFound();
    $this->actingAs($this->tech)->post('/labels/print', ['assets' => [$theirAsset->ulid], 'template' => 'roll_50x30']);

    asTenant($other, fn () => expect(DB::table('asset_label_prints')->count())->toBe(0));
    expect(DB::table('asset_label_prints')->count())->toBe(1);

    Feature::for($this->tech->tenant)->deactivate(Modules::feature('labeling'));
    $this->actingAs($this->tech)->get('/labels')->assertNotFound();
    $this->actingAs($this->tech)->get("/a/{$this->a1->ulid}")->assertOk();
});

it('lists assets with labels.view but prints only with labels.print', function () {
    Role::create(['name' => 'viewer', 'label' => 'Viewer', 'guard_name' => 'web']);
    grantTo('viewer', ['assets.view', 'labels.view']);
    $viewer = userWithRole('viewer');

    $this->actingAs($viewer)->get('/labels')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.print', false));
    $this->actingAs($viewer)->get("/labels/print?assets={$this->a1->ulid}")->assertForbidden();
    $this->actingAs($viewer)->post('/labels/print', ['assets' => [$this->a1->ulid], 'template' => 'roll_50x30'])->assertForbidden();

    $this->actingAs($this->tech)->get('/labels')->assertInertia(fn (Assert $page) => $page->where('can.print', true));
});
