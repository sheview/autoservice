<?php

use App\Modules\Asset\Actions\ApproveCheckoutRequest;
use App\Modules\Asset\Actions\FulfillCheckoutItem;
use App\Modules\Asset\Actions\SaveCheckoutRequest;
use App\Modules\Asset\Actions\SubmitCheckoutRequest;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    $this->branch = Branch::create(['code' => 'BKK', 'name' => 'Bangkok']);
    $this->tech = userWithRole('technician', ['branch_id' => $this->branch->id]);
    $this->helpdesk = userWithRole('helpdesk');
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'Notebook']);
    $this->asset = createAsset($category, [
        'name' => 'Finance laptop', 'brand' => 'ASUS', 'model' => 'X15', 'serial_number' => 'SN-SECRET-1',
        'used_by' => 'Khun Somchai', 'location' => 'Floor 3, Finance', 'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id, 'warranty_expires_at' => now()->addDays(20)->toDateString(),
    ]);
    // Three more of the same model.
    foreach (range(1, 3) as $n) {
        createAsset($category, ['name' => "Laptop {$n}", 'brand' => 'ASUS', 'model' => 'X15', 'serial_number' => "SN-{$n}", 'branch_id' => $this->branch->id]);
    }
    $this->key = $this->asset->fresh()->public_key;
    $this->url = "/t/001/q/{$this->asset->asset_code}";
});

it('shows signed-in staff the whole device, its jobs and what they may do next', function () {
    $open = openTicket($this->helpdesk, ['asset_id' => $this->asset->id, 'customer_id' => $this->customer->id]);
    $done = openTicket($this->helpdesk, ['asset_id' => $this->asset->id, 'customer_id' => $this->customer->id]);
    $done->forceFill(['status' => Ticket::STATUS_CLOSED])->save();

    foreach ([$this->url, "/q/{$this->asset->asset_code}"] as $url) {
        $this->actingAs($this->tech)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Labeling/Qr')
            ->where('asset.serials', ['SN-SECRET-1'])
            ->where('asset.used_by', 'Khun Somchai')
            ->where('asset.customer', 'Acme')
            ->where('asset.warranty', 'expiring')
            ->where('asset.model_units', 4)
            ->where('asset.model_available', 4)
            ->where('openTickets.0.ulid', $open->ulid)
            ->where('history.0.ulid', $done->ulid)
            ->where('can.move', true)
            ->where('can.openTicket', true));
    }

    $this->actingAs($this->tech)->get('/t/001/q/NOPE-0001')
        ->assertInertia(fn (Assert $page) => $page->component('Labeling/Qr')->where('asset', null));
});

it('counts free devices from the same source as the asset list', function () {
    // One device lent out: it reads 0 of 1, the model 3 of 4 free.
    $request = app(SaveCheckoutRequest::class)->handle(null, [
        'borrower_user_id' => $this->helpdesk->id,
        'items' => [['item_type' => 'asset', 'asset_id' => $this->asset->id, 'checkout_type' => 'loan', 'qty' => 1, 'due_return_date' => now()->addWeek()->toDateString()]],
    ], $this->helpdesk);
    app(SubmitCheckoutRequest::class)->handle($request, $this->helpdesk);
    $request->refresh();
    if ($request->status === 'pending') {
        app(ApproveCheckoutRequest::class)->handle($request, userWithRole('admin_company'));
    }
    app(FulfillCheckoutItem::class)->handle($request->items()->first(), 1, userWithRole('admin_company'));

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('asset.available', 0)->where('asset.quantity', 1)->where('asset.model_available', 3));
    $this->actingAs($this->tech)->get('/assets')->assertInertia(fn (Assert $page) => $page
        ->where('assets.data', fn ($rows) => collect($rows)->firstWhere('asset_code', $this->asset->asset_code)['available'] === 0));
});

it('shows anyone else what the device is and its warranty, never where it is or who uses it, and only with the key of the label', function () {
    $this->get("{$this->url}?k={$this->key}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Labeling/QrPublic')
        ->where('company', 'Default')
        ->where('asset', [
            'asset_code' => $this->asset->asset_code, 'name' => 'Finance laptop', 'category' => 'Notebook', 'brand' => 'ASUS', 'model' => 'X15',
            'serials' => ['SN-SECRET-1'], 'status' => 'in_use', 'warranty_expires_at' => now()->addDays(20)->toDateString(), 'warranty' => 'expiring',
            'photo' => null,
        ]));
    $same = fn ($response) => $response->assertInertia(fn (Assert $page) => $page->component('Labeling/QrPublic')->where('asset', null)->where('company', null));
    $same($this->get($this->url));                                   // no key
    $same($this->get("{$this->url}?k=wrongkey"));                    // wrong key
    $same($this->get("/t/001/q/NB-99999?k={$this->key}"));           // no such asset
    $same($this->get("/t/002/q/{$this->asset->asset_code}?k={$this->key}")); // another company's code

    // Never where it is, who uses it or whose it is, anywhere in the public page.
    $html = $this->get("{$this->url}?k={$this->key}")->getContent();
    foreach (['Khun Somchai', 'Floor 3', 'Acme'] as $secret) {
        expect($html)->not->toContain($secret);
    }

    // Staff of another company and customer accounts get the public page too.
    $outsider = userWithRole('helpdesk', [], createTenant('other'));
    $this->actingAs($outsider)->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->component('Labeling/QrPublic'));
});

it('shows on the public page the MA contract of the device, its number and period only', function () {
    $this->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('contract', null));

    $old = createContract($this->customer, ['contract_no' => 'MA-OLD', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
    $old->contractAssets()->create(['asset_id' => $this->asset->id]);
    $this->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('contract.contract_no', 'MA-OLD')->where('contract.phase', 'expired'));

    $running = createContract($this->customer, ['contract_no' => 'MA-2569-001', 'title' => 'Secret title']);
    $running->contractAssets()->create(['asset_id' => $this->asset->id]);
    $response = $this->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('contract', [
        'contract_no' => 'MA-2569-001', 'starts_on' => $running->starts_on->toDateString(), 'ends_on' => $running->ends_on->toDateString(), 'phase' => 'active',
    ]));
    expect($response->getContent())->not->toContain('Secret title')->not->toContain('Acme');

    $this->get("{$this->url}?k=wrongkey")->assertInertia(fn (Assert $page) => $page->where('contract', null));
});

it('brings staff back to the same device after signing in', function () {
    $this->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('signIn', url("t/001/q/{$this->asset->asset_code}/staff").'?k='.$this->key));

    $this->get("{$this->url}/staff?k={$this->key}")->assertRedirect('/login');
    $this->post('/login', ['email' => $this->tech->email, 'password' => 'password', 'remember' => true])
        ->assertRedirect(url("{$this->url}/staff?k={$this->key}"));
    $this->get("{$this->url}/staff?k={$this->key}")->assertRedirect("{$this->url}?k={$this->key}");

    expect(config('auth.guards.web.remember'))->toBe(60 * 24 * 30);
});

it('lets who may move devices change only where it is', function () {
    $other = Branch::create(['code' => 'CNX', 'name' => 'Chiang Mai']);

    $this->actingAs(userWithRole('user'))->post("/assets/{$this->asset->ulid}/move", ['location' => 'x'])->assertForbidden();
    $this->actingAs($this->tech)->post("/assets/{$this->asset->ulid}/move", ['branch_id' => $other->id, 'location' => 'Server room', 'name' => 'Hacked'])
        ->assertSessionHasNoErrors();

    expect($this->asset->fresh()->only(['branch_id', 'location', 'name']))
        ->toBe(['branch_id' => $other->id, 'location' => 'Server room', 'name' => 'Finance laptop']);
});

it('shows the main photo on the public page, only with the key of the label', function () {
    Storage::fake('local');
    $this->actingAs(userWithRole('admin_company'))->post("/assets/{$this->asset->ulid}/photos/0", [
        'photo' => UploadedFile::fake()->image('front.jpg', 400, 300),
    ])->assertSessionHasNoErrors();
    auth()->logout();

    $photo = url("t/001/q/{$this->asset->asset_code}/photo").'?k='.$this->key;
    $this->get("{$this->url}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('asset.photo', $photo));
    $this->get($photo)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get("{$this->url}/photo?k=wrong-key")->assertNotFound();
});
