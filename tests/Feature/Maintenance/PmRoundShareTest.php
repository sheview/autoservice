<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmRoundShare;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000', 'tenancy.public_links' => 'path']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 round', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo('2026-02-10 10:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Boss']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Desk']);
    $this->tech = userWithRole('technician');
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'UPS']);
    $ups = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Alpha']);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $ups->id]);
    createPmTemplate(['name' => 'UPS PM', 'asset_category_id' => $category->id, 'items' => [['key' => 'battery', 'label' => 'แบตเตอรี่', 'type' => 'pass_fail']]]);

    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->period = ['customer_id' => $this->customer->id, 'from' => '2026-01-01', 'to' => '2026-12-31'];

    // An approved work order: the round has something to show.
    $this->approve = function () {
        $url = "/pm-visits/{$this->visit->ulid}";
        $this->actingAs($this->tech)->post("{$url}/start");
        $item = $this->visit->items()->first();
        $this->actingAs($this->tech)->put("{$url}/items/{$item->id}", ['result' => 'passed', 'answers' => ['battery' => 'pass']]);
        $this->actingAs($this->tech)->post("{$url}/submit", ['summary' => 'เรียบร้อย', 'technician_signature' => signaturePng()]);
        $this->actingAs($this->helpdesk)->post("{$url}/move", ['action' => 'review']);
        $this->actingAs($this->admin)->post("{$url}/move", ['action' => 'approve']);
    };
    // Makes a link and returns its path (the token is only in the flashed link).
    $this->share = function (array $data = []) {
        $response = $this->actingAs($this->helpdesk)->post('/pm-round-shares', $data + $this->period + ['days' => 7, 'recipient_name' => 'คุณสมศรี']);
        $response->assertSessionHasNoErrors();

        return parse_url(session('share_url'), PHP_URL_PATH);
    };
    $this->guest = function () {
        auth()->logout();

        return $this;
    };
});

it('shares a round with approved work orders only, and keeps only the hash of the link', function () {
    $this->actingAs($this->helpdesk)->post('/pm-round-shares', $this->period + ['days' => 7])->assertSessionHasErrors('share');
    ($this->approve)();

    $path = ($this->share)();
    $code = CompanyCodes::of(app(TenantContext::class)->id());
    expect($path)->toStartWith("/t/{$code}/pm-round/");

    $token = basename($path);
    $share = PmRoundShare::sole();
    expect(ShareToken::looksValid($token))->toBeTrue()
        ->and($share->token_hash)->toBe(hash('sha256', $token))
        ->and($share->getAttributes())->not->toContain($token)
        ->and($share->expires_at->toDateString())->toBe('2026-02-17')
        ->and($share->customer_id)->toBe($this->customer->id);

    // The PM Reports page lists the customer's links without ever giving one again.
    $this->actingAs($this->helpdesk)->get("/pm-reports?customer_id={$this->customer->id}")->assertInertia(fn (Assert $page) => $page
        ->where('canShare', true)
        ->where('roundShares.0.recipient_name', 'คุณสมศรี')
        ->where('roundShares.0.from', '2026-01-01')
        ->missing('roundShares.0.token_hash'));
});

it('lets a customer open the round without signing in: the list, the whole round and each work order', function () {
    ($this->approve)();
    $path = ($this->share)();
    ($this->guest)();

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/SharedRound')
        ->where('report.customer', 'Acme')
        ->where('report.from', '2026-01-01')
        ->has('report.visits', 1)
        ->where('report.visits.0.total', 1)
        ->where('report.visits.0.passed', 1)
        ->where('report.visits.0.pdf', url("{$path}/visits/{$this->visit->ulid}/pdf")));

    $this->get("{$path}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->get("{$path}/pdf?download=1")->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment;');
    expect($this->get("{$path}/pdf?print=1")->assertOk()->getContent())->toContain('window.print()')->toContain('UPS Alpha');
    $this->get("{$path}/visits/{$this->visit->ulid}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->get("{$path}/visits/{$this->visit->ulid}/pdf?print=1")->assertOk()->getContent())->toContain('window.print()');

    // A work order outside the round does not open through the link.
    $this->get("{$path}/visits/".Str::ulid().'/pdf')->assertNotFound();

    expect(PmRoundShare::sole()->view_count)->toBe(7);
});

it('stops working when expired, revoked, the token is wrong, or on another company', function () {
    ($this->approve)();
    $path = ($this->share)(['allow_download' => false]);
    $notFound = fn (string $url) => $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', null)->where('company', null));

    ($this->guest)()->get("{$path}/pdf?download=1")->assertNotFound();
    $this->get("{$path}/visits/{$this->visit->ulid}/pdf?download=1")->assertNotFound();

    $notFound(substr($path, 0, -1).(str_ends_with($path, 'X') ? 'Y' : 'X'));
    $notFound(substr($path, 0, -5));
    // The right token on another company's path.
    $other = createTenant('other');
    $notFound(preg_replace('#/t/\d+/#', '/t/'.CompanyCodes::of($other->id).'/', $path));

    $this->travelTo('2026-02-18 00:00:01');
    $notFound($path);
    $this->get("{$path}/pdf")->assertNotFound();

    $this->travelTo('2026-02-12');
    $share = PmRoundShare::sole();
    $this->actingAs($this->tech)->delete("/pm-round-shares/{$share->id}")->assertForbidden();
    $this->actingAs($this->helpdesk)->delete("/pm-round-shares/{$share->id}")->assertSessionHasNoErrors();
    expect($share->fresh()->revoked_by_name)->toBe('Desk');
    ($this->guest)();
    $notFound($path);
});

it('lets only staff who may share make links', function () {
    ($this->approve)();

    $this->actingAs($this->tech)->post('/pm-round-shares', $this->period + ['days' => 7])->assertForbidden();
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->post('/pm-round-shares', $this->period + ['days' => 7])->assertForbidden();
    $this->actingAs($this->helpdesk)->post('/pm-round-shares', $this->period + ['days' => 999])->assertSessionHasErrors('days');
    $this->actingAs($this->helpdesk)->post('/pm-round-shares', ['from' => '2026-12-31', 'to' => '2026-01-01', 'days' => 7] + $this->period)
        ->assertSessionHasErrors('to');
    // The page offers no links to who may not share.
    $this->actingAs($this->tech)->get("/pm-reports?customer_id={$this->customer->id}")->assertInertia(fn (Assert $page) => $page
        ->where('canShare', false)->where('roundShares', []));
});

it('keeps a company\'s round links away from another company', function () {
    ($this->approve)();
    $path = ($this->share)();

    $other = createTenant('other');
    [$theirCustomer, $theirShare] = asTenant($other, function () {
        $customer = createCustomer();

        return [$customer, PmRoundShare::create([
            'customer_id' => $customer->id, 'from_on' => '2026-01-01', 'to_on' => '2026-12-31',
            'token_hash' => ShareToken::hash(ShareToken::make()), 'expires_at' => now()->addDays(7),
        ])];
    });

    // Only this company's link is seen, and the other one cannot be revoked from here.
    expect(PmRoundShare::count())->toBe(1);
    $this->actingAs($this->helpdesk)->delete("/pm-round-shares/{$theirShare->id}")->assertNotFound();
    $this->actingAs($this->helpdesk)->get("/pm-reports?customer_id={$theirCustomer->id}")
        ->assertInertia(fn (Assert $page) => $page->where('roundShares', []));
    // Another company's customer cannot be chosen for a link.
    $this->actingAs($this->helpdesk)->post('/pm-round-shares', ['customer_id' => $theirCustomer->id] + $this->period + ['days' => 7])
        ->assertSessionHasErrors('customer_id');
    expect(asTenant($other, fn () => $theirShare->fresh()->revoked_at))->toBeNull();

    // This company's link does not open on the other company's path.
    ($this->guest)()->get(preg_replace('#/t/\d+/#', '/t/'.CompanyCodes::of($other->id).'/', $path))
        ->assertInertia(fn (Assert $page) => $page->where('report', null));
});
