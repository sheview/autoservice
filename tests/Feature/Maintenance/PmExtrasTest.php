<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Notifications\UpcomingPmNotification;
use App\Modules\Document\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-02-10 10:00');

    $this->admin = userWithRole('admin_company');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->asset = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id]);

    $this->contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->contract->contractAssets()->create(['asset_id' => $this->asset->id]);

    $this->plan = app(SavePmPlan::class)->handle(null, [
        'contract_id' => $this->contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id,
    ]);
    $this->visits = $this->plan->visits()->orderBy('round')->get();
});

// --- reminders ------------------------------------------------------------------

it('e-mails each technician their rounds coming up, and managers the unassigned ones, once', function () {
    Notification::fake();
    // round 1 has an appointment in 5 days; round 2 is moved close and has no technician
    $this->visits[0]->update(['scheduled_on' => '2026-02-15']);
    $this->visits[1]->update(['scheduled_on' => '2026-02-12', 'assignee_id' => null]);

    $this->artisan('pm:notify-upcoming')->assertSuccessful();

    Notification::assertSentTo($this->tech, UpcomingPmNotification::class,
        fn ($n) => ! $n->forManagers && $n->visits->pluck('id')->all() === [$this->visits[0]->id]);
    Notification::assertSentTo($this->helpdesk, UpcomingPmNotification::class,
        fn ($n) => $n->forManagers && $n->visits->pluck('id')->all() === [$this->visits[1]->id]);
    Notification::assertSentTo($this->admin, UpcomingPmNotification::class, fn ($n) => $n->forManagers);
    Notification::assertNotSentTo($this->tech, UpcomingPmNotification::class, fn ($n) => $n->forManagers);

    // rounds further away are not mentioned, and nobody is e-mailed twice
    expect(PmVisit::whereNotNull('reminded_at')->count())->toBe(2);
    $this->artisan('pm:notify-upcoming');
    Notification::assertSentToTimes($this->tech, UpcomingPmNotification::class, 1);

    // a new appointment re-arms the reminder
    $this->actingAs($this->helpdesk)->put("/pm-visits/{$this->visits[0]->ulid}", ['scheduled_on' => '2026-02-16', 'assignee_id' => $this->tech->id]);
    expect($this->visits[0]->fresh()->reminded_at)->toBeNull();

    $mail = (new UpcomingPmNotification(collect([$this->visits[0]->fresh()]), [$this->customer->id => 'Acme'], false))->toMail($this->tech);
    expect($mail->subject)->toContain('1')
        ->and(implode("\n", $mail->introLines))->toContain('16/02/2569')
        ->and($mail->actionUrl)->toContain('assignee=me');
});

it('does not remind about rounds that started or of tenants with the module off', function () {
    Notification::fake();
    $this->visits[0]->update(['scheduled_on' => '2026-02-11']);
    app(StartPmVisit::class)->handle($this->visits[0], $this->tech);

    $this->artisan('pm:notify-upcoming');
    Notification::assertNothingSent();
});

// --- photos ----------------------------------------------------------------------

it('keeps site photos of an asset while the round runs, served only through the round', function () {
    Storage::fake('local');
    $visit = app(StartPmVisit::class)->handle($this->visits[0], $this->tech);
    $item = $visit->items()->first();
    $url = "/pm-visits/{$visit->ulid}/items/{$item->id}/photos";

    $this->actingAs($this->tech)->post($url, ['photo' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('photo');
    $this->actingAs($this->tech)->post($url, ['photo' => UploadedFile::fake()->image('rack.jpg', 800, 600)])->assertSessionHasNoErrors();

    $media = Media::first();
    expect($media->collection_name)->toBe(PmVisitItem::PHOTOS)
        ->and($media->getPathRelativeToRoot())->toStartWith("tenants/{$this->tenant->id}/");

    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('items.0.photos.0.name', 'rack.jpg'));
    $this->actingAs($this->helpdesk)->get("{$url}/{$media->id}")->assertOk()->assertHeader('content-type', 'image/jpeg');

    // helpdesk may look but not add; another item does not serve this photo
    $this->actingAs($this->helpdesk)->post($url, ['photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    $other = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id]);
    $otherItem = $visit->items()->create(['asset_id' => $other->id]);
    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}/items/{$otherItem->id}/photos/{$media->id}")->assertNotFound();

    $this->actingAs($this->tech)->delete("{$url}/{$media->id}")->assertSessionHasNoErrors();
    expect(Media::count())->toBe(0);

    // a closed round keeps its photos
    $this->actingAs($this->tech)->post($url, ['photo' => UploadedFile::fake()->image('after.jpg')]);
    $visit->update(['status' => PmVisit::STATUS_COMPLETED]);
    $this->actingAs($this->tech)->delete("{$url}/".Media::first()->id)->assertSessionHasErrors('photo');
    $this->actingAs($this->tech)->post($url, ['photo' => UploadedFile::fake()->image('late.jpg')])->assertSessionHasErrors('photo');
});

// --- customer accounts -------------------------------------------------------------

it('shows a customer account the PM rounds of its own customer only, read only', function () {
    $mine = app(StartPmVisit::class)->handle($this->visits[0], $this->tech);
    $otherCustomer = createCustomer();
    $otherPlan = app(SavePmPlan::class)->handle(null, [
        'contract_id' => createContract($otherCustomer)->id, 'title' => 'PM Other', 'interval_months' => 6,
    ]);
    $theirs = $otherPlan->visits()->first();

    $customerUser = userWithRole('customer', ['customer_id' => $this->customer->id]);

    $this->actingAs($customerUser)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->pluck('href')
            ->filter(fn ($href) => str_contains($href, '/pm-'))->values()->all() === ['/pm-visits']));

    $this->actingAs($customerUser)->get('/pm-visits?status=all')
        ->assertInertia(fn (Assert $page) => $page->where('visits.total', 4)->where('customers', [])->where('assignees', []));
    $this->actingAs($customerUser)->get("/pm-visits/{$mine->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('visit.plan.can_view', false)
            ->where('can', ['update' => false, 'start' => false, 'perform' => false, 'cancel' => false, 'openTicket' => false]));
    $this->actingAs($customerUser)->get("/pm-visits/{$theirs->ulid}")->assertForbidden();
    $this->actingAs($customerUser)->get('/pm-calendar?month=2026-06')
        ->assertInertia(fn (Assert $page) => $page->has('visits', 1));

    $this->actingAs($customerUser)->get("/pm-plans/{$this->plan->id}")->assertForbidden();
    $this->actingAs($customerUser)->get('/pm-checklists')->assertForbidden();
    $this->actingAs($customerUser)->post("/pm-visits/{$mine->ulid}/complete")->assertForbidden();
});

// --- calendar ----------------------------------------------------------------------

it('puts each round of the month on its appointment day, or its due day', function () {
    $this->visits[0]->update(['scheduled_on' => '2026-03-10']);
    $this->visits[1]->update(['scheduled_on' => '2026-03-20', 'status' => PmVisit::STATUS_CANCELLED]);

    $this->actingAs($this->tech)->get('/pm-calendar?month=2026-03')
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Visits/Calendar')
            ->where('filters.month', '2026-03')
            ->has('visits', 1)
            ->where('visits.0.calendar_date', '2026-03-10')
            ->where('visits.0.scheduled', true));

    $this->actingAs($this->tech)->get('/pm-calendar?month=2026-09')
        ->assertInertia(fn (Assert $page) => $page->where('visits.0.calendar_date', '2026-09-30')->where('visits.0.scheduled', false));

    // no month = this month; filters narrow it down
    $this->actingAs($this->tech)->get('/pm-calendar')->assertInertia(fn (Assert $page) => $page->where('filters.month', '2026-02'));
    $this->actingAs($this->tech)->get('/pm-calendar?month=2026-03&assignee=none')->assertInertia(fn (Assert $page) => $page->has('visits', 0));
});
