<?php

use App\Modules\Maintenance\Actions\PmTrackingUrl;
use App\Modules\Maintenance\Actions\SavePmSettings;
use App\Modules\Maintenance\Models\PmSignLink;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmDataHash;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-06-10 09:00');
    $this->tech = userWithRole('technician', ['name' => 'ช่างหนึ่ง']);
    $this->admin = userWithRole('admin_company', ['name' => 'Boss']);
    $category = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    createPmTemplate(['code' => 'UPS', 'asset_category_id' => $category->id, 'items' => [['key' => 'fan', 'label' => 'Fan', 'type' => 'pass_fail']]]);
    $customer = createCustomer(['name' => 'ลูกค้า ก']);
    $contract = createContract($customer);
    $this->asset = createAsset($category, ['customer_id' => $customer->id]);
    $contract->contractAssets()->create(['asset_id' => $this->asset->id]);
    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM UPS', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";

    $this->work = function () {
        $this->actingAs($this->tech)->post("/pm-work/{$this->visit->ulid}/arrive");
        $item = $this->visit->items()->sole();
        $this->actingAs($this->tech)->put("{$this->url}/items/{$item->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass']])->assertSessionHasNoErrors();

        return $item;
    };
    $this->handIn = fn (array $data = []) => $this->actingAs($this->tech)
        ->withHeaders(['User-Agent' => 'TabletBrowser/1.0'])
        ->post("{$this->url}/submit", ['technician_signature' => signaturePng(), ...$data]);
});

it('signs on the technician\'s device: the customer\'s signature locks the results with their hash', function () {
    $item = ($this->work)();
    ($this->handIn)([
        'customer_signature' => signaturePng(), 'customer_signer_name' => 'คุณสมศรี', 'customer_signer_position' => 'IT',
        'customer_signer_phone' => '0812345678', 'customer_signer_email' => 'somsri@example.com', 'satisfaction' => 'good',
    ])->assertSessionHasNoErrors();

    $visit = $this->visit->fresh();
    $customer = $visit->signatureOf('customer');
    expect($visit->locked_at)->not->toBeNull()
        ->and($visit->signatureOf('engineer')->name)->toBe('ช่างหนึ่ง')
        ->and($customer->only(['name', 'position', 'phone', 'email']))->toBe(['name' => 'คุณสมศรี', 'position' => 'IT', 'phone' => '0812345678', 'email' => 'somsri@example.com'])
        ->and($customer->user_agent)->toBe('TabletBrowser/1.0')
        ->and($customer->ip)->toBe('127.0.0.1')
        ->and($customer->data_hash)->toBe(PmDataHash::of($visit))
        ->and(Storage::disk('local')->exists($customer->image_path))->toBeTrue()
        ->and(str_starts_with($customer->image_path, "pm-signatures/{$visit->tenant_id}/"))->toBeTrue();

    // the satisfaction is the work order's survey: 4 levels kept on the 1–5 scale
    $survey = TicketSurvey::where('pm_visit_id', $visit->id)->sole();
    expect($survey->score)->toBe(4)->and($survey->ticket_id)->toBeNull()->and($survey->answered_name)->toBe('คุณสมศรี');

    // locked: nothing about the results changes any more
    $this->actingAs($this->admin)->put("{$this->url}/items/{$item->id}", ['result' => 'failed', 'note' => 'x'])->assertSessionHasErrors('visit');
    $this->actingAs($this->tech)->putJson("/pm-work/{$visit->ulid}/items/{$item->id}/draft", ['answers' => []])->assertUnprocessable();
    $this->actingAs($this->tech)->post("{$this->url}/items/{$item->id}/photos", ['photo' => UploadedFile::fake()->image('x.jpg')])->assertSessionHasErrors();
    $this->actingAs($this->admin)->post("{$this->url}/customer-sign", ['image' => signaturePng(), 'name' => 'คนอื่น'])->assertSessionHasErrors('signature');
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'review'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'return', 'note' => 'x'])->assertSessionHasErrors('action');
    expect(PmDataHash::of($visit->fresh()))->toBe($customer->data_hash);
});

it('corrects a signed work order as a revision, the signed one closed', function () {
    $item = ($this->work)();
    ($this->handIn)(['customer_signature' => signaturePng(), 'customer_signer_name' => 'คุณสมศรี']);

    $this->actingAs($this->tech)->post("{$this->url}/revise", ['note' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->post("{$this->url}/revise")->assertSessionHasErrors('note');
    $this->actingAs($this->admin)->post("{$this->url}/revise", ['note' => 'ลืมถ่ายรูป'])->assertRedirect();

    $old = $this->visit->fresh();
    $revision = PmVisit::where('revision_of_id', $old->id)->sole();
    expect($revision->visit_no)->toBe($old->visit_no.'-R1')
        ->and($revision->status)->toBe(PmVisit::STATUS_REVISION_REQUIRED)
        ->and($revision->locked_at)->toBeNull()
        ->and($revision->signatures()->count())->toBe(0)
        ->and($revision->items()->sole()->only(['asset_id', 'result', 'answers']))->toBe($item->fresh()->only(['asset_id', 'result', 'answers']))
        ->and($old->status)->toBe(PmVisit::STATUS_CANCELLED)
        ->and($old->summary)->toContain($revision->visit_no)
        // the signed one still reads as signed
        ->and($old->locked_at)->not->toBeNull();

    // the revision is worked on and signed again
    $copy = $revision->items()->sole();
    $this->actingAs($this->tech)->put("/pm-visits/{$revision->ulid}/items/{$copy->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass'], 'note' => 'ถ่ายรูปแล้ว'])
        ->assertSessionHasNoErrors();
    // a revision of a revision is -R2
    $this->actingAs($this->tech)->post("/pm-visits/{$revision->ulid}/submit", ['technician_signature' => signaturePng(), 'customer_signature' => signaturePng(), 'customer_signer_name' => 'คุณสมศรี']);
    $this->actingAs($this->admin)->post("/pm-visits/{$revision->ulid}/revise", ['note' => 'อีกครั้ง']);
    expect(PmVisit::where('revision_of_id', $revision->id)->sole()->visit_no)->toBe($old->visit_no.'-R2');
});

it('lets the customer sign on their own phone through a link: once, until it expires', function () {
    ($this->work)();
    ($this->handIn)()->assertSessionHasNoErrors();

    $this->actingAs($this->tech)->post("{$this->url}/sign-link")->assertSessionHas('sign_link');
    $link = session('sign_link');
    $code = CompanyCodes::of($this->visit->tenant_id);
    expect($link['url'])->toStartWith(rtrim(config('app.url'), '/'))->toContain('/pm-sign/')
        ->and($link['qr'])->toContain('<svg');
    $path = parse_url($link['url'], PHP_URL_PATH);
    $token = basename($path);
    // only the hash is kept
    expect(PmSignLink::where('token_hash', $token)->exists())->toBeFalse()->and(PmSignLink::count())->toBe(1);

    auth()->logout();
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Maintenance/PublicSign')
        ->where('work.customer', 'ลูกค้า ก')->where('work.totals.passed', 1));
    $this->post($path, ['image' => signaturePng()])->assertSessionHasErrors('name');
    $this->withHeaders(['User-Agent' => 'Phone/2'])->post($path, ['image' => signaturePng(), 'name' => 'คุณสมศรี', 'satisfaction' => 'excellent'])
        ->assertSessionHasNoErrors();

    $visit = $this->visit->fresh();
    expect($visit->locked_at)->not->toBeNull()
        ->and($visit->signatureOf('customer')->user_agent)->toBe('Phone/2')
        ->and(TicketSurvey::where('pm_visit_id', $visit->id)->sole()->score)->toBe(5);

    // used: it opens nothing any more
    $this->get($path)->assertInertia(fn (Assert $page) => $page->where('work', null));
    $this->post($path, ['image' => signaturePng(), 'name' => 'x'])->assertNotFound();
    // a wrong token, or the token on another company's path
    $this->get('/pm-sign/'.str_repeat('a', 48))->assertInertia(fn (Assert $page) => $page->where('work', null));
    if ($code) {
        $other = createTenant('other');
        $this->get('/t/'.CompanyCodes::of($other->id)."/pm-sign/{$token}")->assertInertia(fn (Assert $page) => $page->where('work', null));
    }
});

it('stops a sign link once it expires or is replaced', function () {
    ($this->work)();
    ($this->handIn)();
    $this->actingAs($this->tech)->post("{$this->url}/sign-link");
    $first = parse_url(session('sign_link')['url'], PHP_URL_PATH);
    $this->actingAs($this->tech)->post("{$this->url}/sign-link");
    $second = parse_url(session('sign_link')['url'], PHP_URL_PATH);
    auth()->logout();

    // a new link replaces the one not used yet
    $this->get($first)->assertInertia(fn (Assert $page) => $page->where('work', null));
    $this->get($second)->assertInertia(fn (Assert $page) => $page->whereNot('work', null));

    // three days by default, to the end of the day
    $this->travelTo('2026-06-14 00:00:01');
    $this->get($second)->assertInertia(fn (Assert $page) => $page->where('work', null));
    $this->post($second, ['image' => signaturePng(), 'name' => 'x'])->assertNotFound();
    expect($this->visit->fresh()->locked_at)->toBeNull();
});

it('approves on its own without an approval step, and asks the approver to sign when set', function () {
    app(SavePmSettings::class)->handle(app(TenantContext::class)->tenant(), [
        'review_step' => false, 'approval_step' => false, 'require_technician_signature' => true, 'require_customer_signature' => false,
        'require_approver_signature' => false, 'share_days' => 14, 'sign_link_days' => 3, 'report_note' => null,
    ], $this->admin);
    ($this->work)();
    ($this->handIn)()->assertSessionHasNoErrors();
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_APPROVED)
        ->and($this->visit->fresh()->current_version)->toBe(1)
        ->and($this->visit->versions()->sole()->approved_by_name)->toBe(__('maintenance.visits.auto_approved'));
});

it('asks the approver to sign when the company says so, and prints the signatures', function () {
    app(SavePmSettings::class)->handle(app(TenantContext::class)->tenant(), [
        'review_step' => false, 'approval_step' => true, 'require_technician_signature' => true, 'require_customer_signature' => false,
        'require_approver_signature' => true, 'share_days' => 14, 'sign_link_days' => 3, 'report_note' => null,
    ], $this->admin);
    ($this->work)();
    ($this->handIn)();
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'approve'])->assertSessionHasErrors('approver_signature');
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'approve', 'approver_signature' => signaturePng()])->assertSessionHasNoErrors();
    expect($this->visit->fresh()->signatureOf('approver')->name)->toBe('Boss')
        ->and($this->visit->versions()->sole()->snapshot['signatures']['approver']['signer'])->toBe('Boss');
});

it('shows where a work order stands from the QR on its sheet, without results', function () {
    ($this->work)();
    $this->actingAs($this->admin)->get("{$this->url}/sheet")->assertOk();
    $token = $this->visit->fresh()->tracking_token;
    $url = app(PmTrackingUrl::class)->handle($this->visit->fresh());
    expect($token)->not->toBeNull()->and($url)->toStartWith(rtrim(config('app.url'), '/'))->toEndWith("/pm-track/{$token}");

    auth()->logout();
    $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Maintenance/PublicTrack')
        ->where('work.status', PmVisit::STATUS_IN_PROGRESS)->where('work.items', 1)->missing('work.answers'));
    $this->get(str_replace($token, str_repeat('b', 48), parse_url($url, PHP_URL_PATH)))->assertInertia(fn (Assert $page) => $page->where('work', null));
    expect(PmVisitItem::count())->toBe(1);
});
