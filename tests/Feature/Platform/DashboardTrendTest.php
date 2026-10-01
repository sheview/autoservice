<?php

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company');
});

/** A ticket opened at $openedAt, closed at $closedAt (if given). */
function ticketAt(string $openedAt, ?string $closedAt = null, array $attributes = []): void
{
    test()->travelTo($openedAt);
    $ticket = openTicket(test()->admin, $attributes);
    if ($closedAt !== null) {
        $ticket->forceFill(['status' => 'closed', 'closed_at' => $closedAt])->save();
    }
    test()->travelTo('2026-10-01 10:00');
}

/** @return list<int> twelve months with $counts (month => count) filled in */
function months(array $counts): array
{
    return array_map(fn (int $month) => $counts[$month] ?? 0, range(1, 12));
}

it('charts the repairs of this year per month and the last five years per year', function () {
    ticketAt('2026-01-15 09:00', '2026-02-03 09:00');
    ticketAt('2026-02-10 09:00');
    ticketAt('2026-02-20 09:00', '2026-02-21 09:00');
    ticketAt('2025-12-30 09:00', '2026-01-05 09:00'); // opened last year, closed this year
    ticketAt('2023-06-01 09:00', '2023-06-02 09:00');

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('trends.year', 2026)
        ->where('trends.years', [2026, 2025, 2024, 2023, 2022])
        ->where('trends.tickets.monthly.opened', months([1 => 1, 2 => 2]))
        ->where('trends.tickets.monthly.closed', months([1 => 1, 2 => 2]))
        ->where('trends.tickets.yearly.years', [2022, 2023, 2024, 2025, 2026])
        ->where('trends.tickets.yearly.opened', [0, 1, 0, 1, 3])
        ->where('trends.tickets.yearly.closed', [0, 1, 0, 0, 3]));
});

it('shows an earlier year on request, and this year for a year it cannot show', function () {
    ticketAt('2025-03-01 09:00');
    ticketAt('2026-03-01 09:00');

    $this->actingAs($this->admin)->get('/dashboard?year=2025')->assertInertia(fn (Assert $page) => $page
        ->where('trends.year', 2025)
        ->where('trends.tickets.monthly.opened', months([3 => 1]))
        ->where('trends.tickets.yearly.years', [2021, 2022, 2023, 2024, 2025]));

    foreach (['2027', '1999', 'abc'] as $year) {
        $this->actingAs($this->admin)->get("/dashboard?year={$year}")->assertInertia(fn (Assert $page) => $page
            ->where('trends.year', 2026)
            ->where('trends.tickets.monthly.opened', months([3 => 1])));
    }
});

it('offers every year back to the oldest ticket or asset', function () {
    ticketAt('2019-05-01 09:00');

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('trends.years', range(2026, 2019)));
});

it('charts repairs within what the user may see', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    $tech = userWithRole('technician', ['branch_id' => $north->id]);
    $category = createAssetCategory();
    // the technician's own ticket (tickets.view scope own), and one of somebody else
    ticketAt('2026-04-01 09:00', null, ['asset_id' => createAsset($category, ['branch_id' => $north->id])->id]);
    ticketAt('2026-04-02 09:00', null, ['asset_id' => createAsset($category, ['branch_id' => $south->id])->id]);
    Ticket::query()->orderBy('id')->first()->forceFill(['assignee_id' => $tech->id])->save();

    $this->actingAs($this->admin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('trends.tickets.monthly.opened', months([4 => 2])));
    $this->actingAs($tech)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('trends.tickets.monthly.opened', months([4 => 1])));
});

it('charts hardware and software apart, on the purchase date or else the registration date', function () {
    $pcs = createAssetCategory();
    $licences = createAssetCategory(['asset_type' => AssetCategory::TYPE_SOFTWARE]);
    createAsset($pcs, ['purchased_at' => '2026-03-10']);
    createAsset($pcs, ['purchased_at' => '2026-03-25']);
    createAsset($pcs, ['purchased_at' => '2024-07-01']);
    createAsset($licences, ['purchased_at' => '2026-05-01']);
    createAsset($licences); // no purchase date: registered today, 1 October 2026

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('trends.assets.monthly.hardware', months([3 => 2]))
        ->where('trends.assets.monthly.software', months([5 => 1, 10 => 1]))
        ->where('trends.assets.yearly.years', [2022, 2023, 2024, 2025, 2026])
        ->where('trends.assets.yearly.hardware', [0, 0, 1, 0, 2])
        ->where('trends.assets.yearly.software', [0, 0, 0, 0, 2]));
});

it('leaves out the charts of modules that are off, and has none for the platform', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('service'));

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('trends.tickets', null)
        ->has('trends.assets.monthly'));

    Feature::for($this->tenant)->deactivate(Modules::feature('asset'));
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('trends', null));

    $this->actingAs(createSuperadmin())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('trends', null));
});

it('keeps each company to its own figures', function () {
    ticketAt('2026-06-01 09:00');
    createAsset(createAssetCategory(), ['purchased_at' => '2026-06-01']);

    $other = createTenant('other');
    $otherAdmin = userWithRole('admin_company', [], $other);

    $this->actingAs($otherAdmin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('trends.tickets.monthly.opened', months([]))
        ->where('trends.assets.monthly.hardware', months([])));
});
