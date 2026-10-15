<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\ShareToken;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000', 'tenancy.public_links' => 'path']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 shared', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo('2026-02-10 10:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Boss']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Desk']);
    $this->tech = userWithRole('technician');
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'UPS']);
    $ups = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Alpha']);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $ups->id]);
    PmChecklist::create(['name' => 'UPS PM', 'asset_category_id' => $category->id, 'items' => [['key' => 'battery', 'label' => 'แบตเตอรี่', 'type' => 'pass_fail']]]);

    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";
    $this->move = fn ($user, string $action, ?string $note = null) => $this->actingAs($user)->post("{$this->url}/move", ['action' => $action, 'note' => $note]);
    $this->approve = function (string $summary) {
        $this->actingAs($this->tech)->post("{$this->url}/submit", ['summary' => $summary, 'technician_signature' => signaturePng()]);
        ($this->move)($this->helpdesk, 'review');
        ($this->move)($this->admin, 'approve');
    };

    $this->actingAs($this->tech)->post("{$this->url}/start");
    $item = $this->visit->items()->first();
    $this->actingAs($this->tech)->put("{$this->url}/items/{$item->id}", ['result' => 'ok', 'answers' => ['battery' => 'pass']]);
    ($this->approve)('ฉบับแรก');

    // Makes a link and returns its path (the token is only in the flashed link).
    $this->share = function (array $data = []) {
        $response = $this->actingAs($this->helpdesk)->post("{$this->url}/shares", $data + ['days' => 7, 'recipient_name' => 'คุณสมศรี']);
        $response->assertSessionHasNoErrors();

        return parse_url(session('share_url'), PHP_URL_PATH);
    };
    // A visitor without an account.
    $this->guest = function () {
        auth()->logout();

        return $this;
    };
});

it('shares only a published report, and keeps only the hash of the link', function () {
    $this->actingAs($this->helpdesk)->post("{$this->url}/shares", ['days' => 7])->assertSessionHasErrors('share');
    ($this->move)($this->admin, 'publish');

    $path = ($this->share)();
    $code = CompanyCodes::of(app(TenantContext::class)->id());
    expect($path)->toStartWith("/t/{$code}/pm-report/");

    $token = basename($path);
    $share = PmReportShare::sole();
    expect(ShareToken::looksValid($token))->toBeTrue()
        ->and($share->token_hash)->toBe(hash('sha256', $token))
        ->and($share->getAttributes())->not->toContain($token)
        ->and($share->expires_at->toDateString())->toBe('2026-02-17')
        ->and($share->recipient_name)->toBe('คุณสมศรี');

    // The page lists the link without ever giving it again.
    $this->actingAs($this->helpdesk)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('can.share', true)
        ->where('shares.0.recipient_name', 'คุณสมศรี')
        ->missing('shares.0.token_hash'));
});

it('lets a customer open the shared report without signing in, and counts each opening', function () {
    ($this->move)($this->admin, 'publish');
    $path = ($this->share)();
    ($this->guest)();

    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/SharedReport')
        ->where('report.visit.visit_no', $this->visit->visit_no)
        ->where('report.version', 1)
        ->where('report.visit.summary', 'ฉบับแรก')
        ->where('report.items.0.name', 'UPS Alpha'));
    $this->get("{$path}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("{$path}/pdf?download=1")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="'.$this->visit->visit_no.'_v1.pdf"');
    $itemId = $this->visit->items()->value('id');
    $this->get("{$path}/equipment/{$itemId}/pdf")->assertOk();
    $this->get("{$path}/equipment/999999/pdf")->assertNotFound();

    expect(PmReportShare::sole()->view_count)->toBe(5)
        ->and(PmVisitEvent::where('type', 'share_viewed')->count())->toBe(4)
        ->and(PmVisitEvent::where('type', 'share_downloaded')->count())->toBe(1);
});

it('stops working when expired, revoked, or the token is wrong', function () {
    ($this->move)($this->admin, 'publish');
    $path = ($this->share)(['allow_download' => false]);
    $notFound = fn (string $url) => $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', null)->where('company', null));

    // No download when the link does not allow it.
    ($this->guest)()->get("{$path}/pdf?download=1")->assertNotFound();

    // A changed or malformed token.
    $notFound(substr($path, 0, -1).'X');
    $notFound(substr($path, 0, -5));
    // The right token on another company's path.
    $other = createTenant('other');
    $notFound(preg_replace('#/t/\d+/#', '/t/'.CompanyCodes::of($other->id).'/', $path));

    $this->travelTo('2026-02-18 00:00:01');
    $notFound($path);
    $this->get("{$path}/pdf")->assertNotFound();

    $this->travelTo('2026-02-12');
    $share = PmReportShare::sole();
    $this->actingAs($this->tech)->delete("{$this->url}/shares/{$share->id}")->assertForbidden();
    $this->actingAs($this->helpdesk)->delete("{$this->url}/shares/{$share->id}")->assertSessionHasNoErrors();
    expect($share->fresh()->revoked_by_name)->toBe('Desk');
    ($this->guest)();
    $notFound($path);
});

it('shows the version it was made for, or always the newest published one', function () {
    ($this->move)($this->admin, 'publish');
    $pinned = ($this->share)();
    $latest = ($this->share)(['follow_latest' => true]);

    ($this->move)($this->admin, 'reopen', 'แก้');
    ($this->approve)('ฉบับแก้ไข');
    // Approved but not published yet: both still show version 1.
    $this->get($latest)->assertInertia(fn (Assert $page) => $page->where('report.version', 1));

    ($this->move)($this->admin, 'publish');
    $this->get($pinned)->assertInertia(fn (Assert $page) => $page->where('report.version', 1)->where('report.visit.summary', 'ฉบับแรก'));
    $this->get($latest)->assertInertia(fn (Assert $page) => $page->where('report.version', 2)->where('report.visit.summary', 'ฉบับแก้ไข'));
});

it('lets only who may share make links, and lists published reports to who may see them', function () {
    ($this->move)($this->admin, 'publish');

    $this->actingAs($this->tech)->post("{$this->url}/shares", ['days' => 7])->assertForbidden();
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->post("{$this->url}/shares", ['days' => 7])->assertForbidden();
    $this->actingAs($this->helpdesk)->post("{$this->url}/shares", ['days' => 999])->assertSessionHasErrors('days');

    // Customer Reports: the customer's own published reports; another customer's account sees none.
    $this->actingAs($client)->get('/pm-customer-reports')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/CustomerReports')
        ->where('reports.total', 1)
        ->where('reports.data.0.visit_no', $this->visit->visit_no)
        ->where('staff', false)
        ->missing('reports.data.0.live_shares'));
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get('/pm-customer-reports')
        ->assertInertia(fn (Assert $page) => $page->where('reports.total', 0));
    $this->actingAs($this->helpdesk)->get('/pm-customer-reports?search='.$this->visit->visit_no)
        ->assertInertia(fn (Assert $page) => $page->where('reports.total', 1));
    $this->actingAs($this->tech)->get('/pm-customer-reports')->assertForbidden();
});
