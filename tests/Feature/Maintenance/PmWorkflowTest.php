<?php

use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SavePmSettings;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-02-10 10:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Boss']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Desk']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);

    $category = createAssetCategory(['name' => 'Switch']);
    $this->switch = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'Core switch']);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $this->switch->id]);
    createPmTemplate(['name' => 'Switch PM', 'asset_category_id' => $category->id, 'items' => [
        ['key' => 'fan', 'label' => 'พัดลม', 'type' => 'pass_fail'],
        ['key' => 'temp', 'label' => 'อุณหภูมิ', 'type' => 'measure', 'unit' => '°C', 'max' => 45],
    ]]);

    $plan = app(SavePmPlan::class)->handle(null, [
        'contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id,
    ]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";

    // The technician does the work: start, check the switch.
    $this->work = function () {
        $this->actingAs($this->tech)->post("{$this->url}/start")->assertSessionHasNoErrors();
        $item = $this->visit->items()->first();
        $this->actingAs($this->tech)->put("{$this->url}/items/{$item->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass', 'temp' => 38]])
            ->assertSessionHasNoErrors();

        return $item;
    };
    $this->submit = fn (array $data = []) => $this->actingAs($this->tech)->post("{$this->url}/submit", $data + ['technician_signature' => signaturePng()]);
    $this->move = fn ($user, string $action, ?string $note = null) => $this->actingAs($user)->post("{$this->url}/move", ['action' => $action, 'note' => $note]);
});

it('takes a work order from the field to the customer: submit, review, approve, publish, close', function () {
    ($this->work)();
    ($this->submit)(['summary' => 'ทุกเครื่องปกติ'])->assertSessionHasNoErrors();
    expect($this->visit->fresh()->only(['status', 'submitted_by']))->toBe(['status' => PmVisit::STATUS_SUBMITTED, 'submitted_by' => $this->tech->id])
        ->and($this->visit->fresh()->hasMedia(PmVisit::TECHNICIAN_SIGNATURE))->toBeTrue();

    ($this->move)($this->helpdesk, 'review')->assertSessionHasNoErrors();
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_PENDING_APPROVAL);

    ($this->move)($this->admin, 'approve', 'ตรวจครบ')->assertSessionHasNoErrors();
    $visit = $this->visit->fresh();
    expect($visit->status)->toBe(PmVisit::STATUS_APPROVED)
        ->and($visit->approved_by)->toBe($this->admin->id)
        ->and($visit->current_version)->toBe(1);

    // The approved report is frozen: its snapshot is what the PDFs print.
    $version = PmReportVersion::sole();
    expect($version->version)->toBe(1)
        ->and($version->approved_by_name)->toBe('Boss')
        ->and($version->snapshot['visit']['summary'])->toBe('ทุกเครื่องปกติ')
        ->and($version->snapshot['visit']['approved_by'])->toBe('Boss')
        ->and($version->snapshot['items'][0]['asset']['name'])->toBe('Core switch')
        ->and($version->snapshot['items'][0]['checks'][1])->toMatchArray(['answer' => 38.0, 'unit' => '°C', 'verdict' => 'pass'])
        ->and($version->snapshot['signatures']['technician']['signer'])->toBe('Tech One')
        ->and($version->snapshot['signatures']['technician']['image'])->toStartWith('data:image/png;base64,');

    ($this->move)($this->admin, 'publish')->assertSessionHasNoErrors();
    ($this->move)($this->admin, 'close')->assertSessionHasNoErrors();
    expect($this->visit->fresh()->only(['status', 'closed_by']))->toBe(['status' => PmVisit::STATUS_CLOSED, 'closed_by' => $this->admin->id]);

    // Every step is in the lasting history, in order.
    expect(PmVisitEvent::where('pm_visit_id', $this->visit->id)->orderBy('id')->pluck('type')->all())->toBe([
        'start', 'signed', 'submit', 'review', 'approve', 'version_created', 'publish', 'close',
    ]);
    $this->actingAs($this->admin)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('visit.status', 'closed')
        ->where('versions.0.version', 1)
        ->where('events.0.type', 'close')
        ->where('moves', []));
});

it('sends handed-in work back with the reason, and takes it again once fixed', function () {
    $item = ($this->work)();
    ($this->submit)()->assertSessionHasNoErrors();

    ($this->move)($this->helpdesk, 'return')->assertSessionHasErrors('note');
    ($this->move)($this->helpdesk, 'return', 'ใส่ค่าอุณหภูมิผิดหน่วย')->assertSessionHasNoErrors();
    expect($this->visit->fresh()->only(['status', 'revision_note', 'revision_count']))
        ->toBe(['status' => PmVisit::STATUS_REVISION_REQUIRED, 'revision_note' => 'ใส่ค่าอุณหภูมิผิดหน่วย', 'revision_count' => 1]);

    // The technician fixes it and hands it in again; the earlier signature still counts.
    $this->actingAs($this->tech)->put("{$this->url}/items/{$item->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass', 'temp' => 40]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/submit")->assertSessionHasNoErrors();
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_SUBMITTED);

    // Pending approval, only an approver may send it back.
    ($this->move)($this->helpdesk, 'review')->assertSessionHasNoErrors();
    ($this->move)($this->helpdesk, 'return', 'x')->assertForbidden();
    ($this->move)($this->admin, 'return', 'ขอรูปหน้าตู้')->assertSessionHasNoErrors();
    expect($this->visit->fresh()->revision_count)->toBe(2);
});

it('goes straight to the approver when the company has no review step', function () {
    app(SavePmSettings::class)->handle(app(TenantContext::class)->tenant(), [
        'review_step' => false, 'require_technician_signature' => false, 'require_customer_signature' => true,
        'share_days' => 14, 'report_note' => null,
    ], $this->admin);

    ($this->work)();
    // The customer must sign now, with a name; the technician need not.
    $this->actingAs($this->tech)->post("{$this->url}/submit")->assertSessionHasErrors('customer_signature');
    $this->actingAs($this->tech)->post("{$this->url}/submit", ['customer_signature' => signaturePng()])->assertSessionHasErrors('customer_signer_name');
    $this->actingAs($this->tech)->post("{$this->url}/submit", ['customer_signature' => signaturePng(), 'customer_signer_name' => 'คุณสมศรี'])
        ->assertSessionHasNoErrors();

    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_PENDING_APPROVAL)
        ->and($this->visit->fresh()->getFirstMedia(PmVisit::CUSTOMER_SIGNATURE)->getCustomProperty('signer'))->toBe('คุณสมศรี');
});

it('reopens an approved report for a correction, which becomes version 2', function () {
    ($this->work)();
    ($this->submit)(['summary' => 'ฉบับแรก']);
    ($this->move)($this->helpdesk, 'review');
    ($this->move)($this->admin, 'approve');

    ($this->move)($this->admin, 'reopen')->assertSessionHasErrors('note');
    ($this->move)($this->admin, 'reopen', 'แก้ชื่อผู้รับงาน')->assertSessionHasNoErrors();
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_REVISION_REQUIRED);

    ($this->submit)(['summary' => 'ฉบับแก้ไข']);
    ($this->move)($this->helpdesk, 'review');
    ($this->move)($this->admin, 'approve');

    $versions = PmReportVersion::orderBy('version')->get();
    expect($versions->pluck('version')->all())->toBe([1, 2])
        // Version 1 still reads as it was approved.
        ->and($versions[0]->snapshot['visit']['summary'])->toBe('ฉบับแรก')
        ->and($versions[1]->snapshot['visit']['summary'])->toBe('ฉบับแก้ไข')
        ->and($this->visit->fresh()->current_version)->toBe(2);
});

it('lets each step be made only by who may make it, and only from its status', function () {
    ($this->work)();

    // Not handed in yet: nobody reviews or approves.
    ($this->move)($this->admin, 'approve')->assertSessionHasErrors('action');
    ($this->submit)();

    // The technician does not check their own work; helpdesk reviews but does not approve or publish.
    ($this->move)($this->tech, 'review')->assertForbidden();
    ($this->move)($this->helpdesk, 'approve')->assertForbidden();
    ($this->move)($this->helpdesk, 'review')->assertSessionHasNoErrors();
    ($this->move)($this->helpdesk, 'approve')->assertForbidden();
    ($this->move)($this->admin, 'approve')->assertSessionHasNoErrors();
    ($this->move)($this->helpdesk, 'publish')->assertForbidden();

    // Start and submit have their own routes; unknown moves are refused.
    ($this->move)($this->admin, 'submit')->assertSessionHasErrors('action');
    ($this->move)($this->admin, 'explode')->assertSessionHasErrors('action');

    // A customer account only reads its own work orders.
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('moves', [])->where('events', []));
    ($this->move)($client, 'publish')->assertForbidden();

    // The buttons offered are those the user may press now.
    $this->actingAs($this->admin)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('moves', ['publish', 'close', 'reopen']));
    $this->actingAs($this->helpdesk)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('moves', []));
});

it('keeps PM settings to who may manage them', function () {
    $this->actingAs($this->admin)->get('/pm-settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Settings')->where('settings.review_step', true));
    $this->actingAs($this->admin)->put('/pm-settings', [
        'review_step' => false, 'require_technician_signature' => true, 'require_customer_signature' => false,
        'share_days' => 400, 'report_note' => 'x',
    ])->assertSessionHasErrors('share_days');
    $this->actingAs($this->admin)->put('/pm-settings', [
        'review_step' => false, 'require_technician_signature' => true, 'require_customer_signature' => false,
        'share_days' => 45, 'report_note' => 'รับประกันงาน 30 วัน',
    ])->assertSessionHasNoErrors();
    expect(app(TenantContext::class)->tenant()->fresh()->settings['pm'])->toMatchArray(['review_step' => false, 'share_days' => 45]);

    $this->actingAs($this->helpdesk)->get('/pm-settings')->assertForbidden();
    $this->actingAs(userWithRole('customer_it', ['customer_id' => $this->customer->id]))->get('/pm-settings')->assertForbidden();
});

it('shows a customer account the schedule and equipment, never the work in the making', function () {
    $item = ($this->work)();
    $this->actingAs($this->tech)->post("{$this->url}/items/{$item->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')]);
    ($this->submit)(['summary' => 'สรุปภายใน']);
    ($this->move)($this->helpdesk, 'return', 'เหตุผลภายในของหัวหน้า');
    $photo = $item->fresh()->getFirstMedia('photos');

    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('visit.revision_note', null)
        ->where('visit.summary', null)
        ->where('items.0.asset.name', 'Core switch')
        ->where('items.0.result', 'pending')
        ->where('items.0.note', null)
        ->where('items.0.photos', [])
        ->where('versions', []));
    $this->actingAs($client)->get("{$this->url}/items/{$item->id}/photos/{$photo->id}")->assertNotFound();
    // Staff still see it all.
    $this->actingAs($this->helpdesk)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('visit.revision_note', 'เหตุผลภายในของหัวหน้า')->where('items.0.result', 'passed'));
});

it('refuses a move whose status changed after the permission was checked', function () {
    ($this->work)();
    ($this->submit)();
    $stale = $this->visit->fresh();
    // Someone else passes it on before the reviewer's "return" arrives.
    app(MovePmVisit::class)->handle($this->visit->fresh(), 'review', $this->helpdesk);

    expect(fn () => app(MovePmVisit::class)->handle($stale, 'return', $this->helpdesk, 'x'))
        ->toThrow(ValidationException::class);
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_PENDING_APPROVAL);
});
