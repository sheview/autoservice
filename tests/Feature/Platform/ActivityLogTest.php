<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Platform\Models\Activity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Somsak Tech']);
    $this->asset = createAsset(createAssetCategory(), ['asset_code' => 'SW-001', 'status' => Asset::STATUS_SPARE]);
    $this->log = fn (string $description, string $at, ?object $causer = null) => tap(
        activity()->performedOn($this->asset)->causedBy($causer)->event('test')->withProperties(['note' => "{$description} note"])->log($description),
        fn (Activity $activity) => $activity->forceFill(['created_at' => $at, 'updated_at' => $at])->save(),
    );
});

it('shows who did what, with search, filters, sort and pages', function () {
    ($this->log)('ขอเบิก/ยืม', '2026-09-30 09:00', $this->tech);
    ($this->log)('อนุมัติเบิก/ยืม', '2026-10-01 09:00', $this->admin);

    $this->actingAs($this->admin)->get('/activity-log?search='.urlencode('เบิก/ยืม'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Platform/ActivityLog')
        ->where('keepDays', 90)
        ->where('entries.data.0.description', 'อนุมัติเบิก/ยืม')
        ->where('entries.data.0.actor', 'Admin Boss')
        ->where('entries.data.0.subject', 'Asset')
        ->where('entries.data.0.details.note', 'อนุมัติเบิก/ยืม note')
        ->missing('entries.data.0.details.actor'));

    $this->actingAs($this->admin)->get('/activity-log?search=somsak')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('description')->contains('ขอเบิก/ยืม')
            && ! collect($rows)->pluck('description')->contains('อนุมัติเบิก/ยืม')));
    $this->actingAs($this->admin)->get('/activity-log?from=2026-10-01&to=2026-10-01&search=เบิก')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('description')->all() === ['อนุมัติเบิก/ยืม']));
    $this->actingAs($this->admin)->get('/activity-log?direction=asc&subject='.urlencode(Asset::class))->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.subject', 'Asset')
        ->where('filters.subject', Asset::class));
    // a date that is not one is ignored
    $this->actingAs($this->admin)->get('/activity-log?from=2026-02-31')->assertInertia(fn (Assert $page) => $page->where('filters.from', null));
});

it('is for the company admin and never shows another tenant', function () {
    $this->get('/activity-log')->assertRedirect('/login');
    ($this->log)('ของเรา', '2026-10-01 09:00', $this->admin);
    asTenant(createTenant('other'), fn () => activity()->event('test')->log('ของบริษัทอื่น'));

    $this->actingAs($this->admin)->get('/activity-log?search=ของ')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('description')->all() === ['ของเรา']));

    foreach ([$this->tech, userWithRole('helpdesk'), userWithRole('user')] as $user) {
        $this->actingAs($user)->get('/activity-log')->assertForbidden();
    }
});

it('keeps 90 days in every tenant', function () {
    ($this->log)('old', '2026-07-01 09:00');
    ($this->log)('recent', '2026-07-05 09:00');
    $other = createTenant('other');
    asTenant($other, fn () => tap(activity()->event('test')->log('other old'), fn (Activity $a) => $a->forceFill(['created_at' => '2026-06-01'])->save()));

    $this->artisan('activitylog:prune')->assertSuccessful();

    expect(Activity::where('event', 'test')->pluck('description')->all())->toBe(['recent'])
        ->and(asTenant($other, fn () => Activity::where('event', 'test')->count()))->toBe(0);
});
