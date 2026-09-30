<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-06-20 10:00');

    $this->admin = userWithRole('admin_company');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);

    // Opens a ticket at $at and walks it along $moves (assign, start, resolve, approve, cancel), an hour apart.
    $this->ticket = function (string $at, array $moves = [], array $attributes = []) {
        $this->travelTo($at);
        $ticket = openTicket($this->helpdesk, $attributes + ['customer_id' => $this->acme->id]);
        foreach ($moves as $move) {
            $this->travel(1)->hours();
            match ($move) {
                'assign' => app(AssignTicket::class)->handle($ticket, $this->tech->id, $this->helpdesk),
                'start', 'resolve' => app(MoveTicket::class)->handle($ticket, $move, $this->tech),
                'approve' => app(MoveTicket::class)->handle($ticket, 'approve', $this->admin),
                'cancel' => app(MoveTicket::class)->handle($ticket, 'cancel', $this->helpdesk, 'duplicate'),
            };
        }
        $this->travelTo('2026-06-20 10:00');

        return $ticket;
    };
});

it('sums up tickets, customers, technicians, parts and surveys of the period', function () {
    $closed = ($this->ticket)('2026-06-02 09:00', ['assign', 'start'], ['priority' => 'high']);
    ($this->ticket)('2026-06-02 14:00', ['assign', 'start']);
    ($this->ticket)('2026-06-10 09:00', ['cancel'], ['customer_id' => $this->beta->id]);
    ($this->ticket)('2026-05-28 09:00', ['assign']);                       // opened before the period, still open

    // a part used on the first ticket, which is then resolved (3 hours after it was opened),
    // closed, and its survey answered
    $ram = createPart(['code' => 'RAM', 'name' => 'Memory', 'unit_cost' => 100000, 'min_qty' => 9], stock: 10);
    $this->travelTo('2026-06-02 11:30');
    $this->actingAs($this->tech)->post("/tickets/{$closed->ulid}/parts", ['part_id' => $ram->id, 'quantity' => 3]);
    $this->actingAs($this->tech)->post("/tickets/{$closed->ulid}/parts/return", ['part_id' => $ram->id, 'quantity' => 1]);
    $this->travelTo('2026-06-02 12:00');
    app(MoveTicket::class)->handle($closed, 'resolve', $this->tech);
    app(MoveTicket::class)->handle($closed, 'approve', $this->admin);
    $this->actingAs($this->admin)->post("/tickets/{$closed->ulid}/survey", ['score' => 4]);
    $this->travelTo('2026-06-20 10:00');

    $this->actingAs($this->helpdesk)->get('/reports?from=2026-06-01&to=2026-06-30')
        ->assertInertia(fn (Assert $page) => $page->component('Reporting/Index')
            ->where('report.period', ['from' => '2026-06-01', 'to' => '2026-06-30'])
            ->where('report.tickets.opened', 3)
            ->where('report.tickets.closed', 1)
            ->where('report.tickets.cancelled', 1)
            ->where('report.tickets.backlog', 2)
            ->where('report.tickets.avg_resolve_hours', 3)
            ->where('report.tickets.by_status.closed', 1)
            ->where('report.tickets.by_status.in_progress', 1)
            ->where('report.tickets.by_status.cancelled', 1)
            ->where('report.tickets.by_status.new', 0)
            ->where('report.tickets.by_priority.high', 1)
            ->where('report.tickets.by_priority.medium', 2)
            // out of contract: no SLA clock runs
            ->where('report.tickets.sla.resolve', ['met' => 0, 'breached' => 0, 'pending' => 0, 'rate' => null])
            ->where('report.tickets.trend_unit', 'day')
            ->has('report.tickets.trend', 30)
            ->where('report.tickets.trend.1', ['label' => '2026-06-02', 'count' => 2])
            ->where('report.tickets.trend.0.count', 0)
            ->missing('report.tickets.by_customer')
            ->where('report.customers', [['name' => 'Acme', 'tickets' => 2], ['name' => 'Beta', 'tickets' => 1]])
            ->where('report.technicians', [[
                'name' => 'Tech One', 'tickets' => 2, 'closed' => 1, 'resolve_breached' => 0, 'answers' => 1, 'average' => 4,
            ]])
            ->where('report.parts.received', 10)
            ->where('report.parts.used', 2)
            ->where('report.parts.used_on_tickets', 2)
            ->where('report.parts.used_value', '2000.00')
            ->where('report.parts.low', 1)
            ->where('report.parts.top', [['code' => 'RAM', 'name' => 'Memory', 'unit' => 'pcs', 'quantity' => 2, 'value' => '2000.00']])
            ->where('report.surveys.sent', 1)
            ->where('report.surveys.average', 4)
            ->where('report.surveys.response_rate', 100)
            ->missing('report.surveys.by_assignee'));

    // another period: only what happened then
    $this->actingAs($this->helpdesk)->get('/reports?from=2026-05-01&to=2026-05-31')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.tickets.opened', 1)
            ->where('report.tickets.closed', 0)
            ->where('report.tickets.backlog', 2)
            ->where('report.parts.used', 0)
            ->where('report.parts.top', [])
            ->where('report.surveys.sent', 0)
            ->where('report.technicians.0.tickets', 1));
});

it('counts SLA results, PM rounds and assets', function () {
    $category = createAssetCategory(['name' => 'Switch']);
    $asset = createAsset($category, ['customer_id' => $this->acme->id, 'warranty_expires_at' => '2026-07-15']);
    createAsset($category, ['status' => Asset::STATUS_SPARE, 'warranty_expires_at' => '2026-01-01']);
    createAsset(createAssetCategory(['name' => 'Server']), ['status' => Asset::STATUS_RETIRED, 'warranty_expires_at' => '2026-01-01']);

    $contract = createContract($this->acme, [
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'service_window' => '24x7',
        'slas' => ['medium' => ['response_minutes' => 90, 'resolve_minutes' => 120]],
    ]);
    $contract->contractAssets()->create(['asset_id' => $asset->id]);

    // started after 2 hours (response breached), resolved after 3 (resolve breached)
    ($this->ticket)('2026-06-03 09:00', ['assign', 'start', 'resolve'], ['asset_id' => $asset->id, 'contract_id' => $contract->id]);
    // opened 30 minutes ago: both clocks still running
    ($this->ticket)('2026-06-20 09:30', [], ['asset_id' => $asset->id, 'contract_id' => $contract->id]);

    $this->actingAs($this->admin)->get('/reports?from=2026-06-01&to=2026-06-30')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.tickets.sla.response', ['met' => 0, 'breached' => 1, 'pending' => 1, 'rate' => 0])
            ->where('report.tickets.sla.resolve', ['met' => 0, 'breached' => 1, 'pending' => 1, 'rate' => 0])
            ->where('report.pm', ['due' => 0, 'completed' => 0, 'on_time' => 0, 'in_progress' => 0, 'overdue' => 0, 'scheduled' => 0,
                'cancelled' => 0, 'compliance' => null, 'items' => ['ok' => 0, 'issue' => 0, 'skipped' => 0, 'pending' => 0]])
            ->where('report.assets.total', 3)
            ->where('report.assets.by_status', ['in_use' => 1, 'spare' => 1, 'in_repair' => 0, 'retired' => 1])
            ->where('report.assets.by_category', [['name' => 'Switch', 'count' => 2], ['name' => 'Server', 'count' => 1]])
            ->where('report.assets.warranty_expiring', 1)
            // the retired one does not count
            ->where('report.assets.warranty_expired', 1));
});

it('defaults to this month and tolerates bad dates', function () {
    $this->actingAs($this->admin)->get('/reports')
        ->assertInertia(fn (Assert $page) => $page->where('report.period', ['from' => '2026-06-01', 'to' => '2026-06-20']));
    $this->actingAs($this->admin)->get('/reports?from=nonsense&to=2026-13-45')
        ->assertInertia(fn (Assert $page) => $page->where('report.period', ['from' => '2026-06-01', 'to' => '2026-06-20']));
    // swapped dates are put in order
    $this->actingAs($this->admin)->get('/reports?from=2026-03-31&to=2026-03-01')
        ->assertInertia(fn (Assert $page) => $page->where('report.period', ['from' => '2026-03-01', 'to' => '2026-03-31']));
    // a long period is cut to two years and shown per month
    $this->actingAs($this->admin)->get('/reports?from=2020-01-01&to=2026-06-20')
        ->assertInertia(fn (Assert $page) => $page->where('report.period.from', '2024-06-19')->where('report.tickets.trend_unit', 'month'));
});

it('leaves out the sections of modules that are switched off', function () {
    foreach (['maintenance', 'inventory', 'survey'] as $module) {
        Feature::for($this->tenant)->deactivate(Modules::feature($module));
    }

    $this->actingAs($this->admin)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->where('report.pm', null)->where('report.parts', null)->where('report.surveys', null)
        ->where('report.tickets.opened', 0)->where('report.assets.total', 0));

    Feature::for($this->tenant)->deactivate(Modules::feature('reporting'));
    $this->actingAs($this->admin)->get('/reports')->assertNotFound();
    $this->actingAs($this->admin)->get('/reports/export')->assertNotFound();
});

it('is for office staff only and never counts another tenant', function () {
    $this->get('/reports')->assertRedirect('/login');
    $this->get('/reports/export')->assertRedirect('/login');

    ($this->ticket)('2026-06-05 09:00');

    $other = createTenant('other');
    asTenant($other, function () {
        openTicket(userWithRole('helpdesk'));
        createAsset(createAssetCategory());
        createPart(stock: 5);
    });

    $this->actingAs($this->admin)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->where('report.tickets.opened', 1)->where('report.assets.total', 0)->where('report.parts.received', 0));

    foreach (['/reports', '/reports/export'] as $url) {
        $this->actingAs($this->tech)->get($url)->assertForbidden();
        $this->actingAs(userWithRole('user'))->get($url)->assertForbidden();
        $this->actingAs(userWithRole('customer', ['customer_id' => $this->acme->id]))->get($url)->assertForbidden();
    }
});

it('exports the report of the period as an Excel workbook', function () {
    $closed = ($this->ticket)('2026-06-02 09:00', ['assign', 'start']);
    $ram = createPart(['code' => 'RAM', 'name' => 'Memory', 'unit_cost' => 50000], stock: 4);
    $this->actingAs($this->tech)->post("/tickets/{$closed->ulid}/parts", ['part_id' => $ram->id, 'quantity' => 1]);
    app(MoveTicket::class)->handle($closed, 'resolve', $this->tech);
    app(MoveTicket::class)->handle($closed, 'approve', $this->admin);

    $response = $this->actingAs($this->helpdesk)->get('/reports/export?from=2026-06-01&to=2026-06-30')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('report-2026-06-01-2026-06-30.xlsx');

    $sheets = Excel::toCollection(null, $response->getFile()->getPathname())->map->toArray();
    $summary = collect($sheets[0])->mapWithKeys(fn (array $row) => [$row[1] => $row[2]]);

    expect($sheets)->toHaveCount(4)
        ->and($sheets[0][0])->toBe(['หมวด', 'รายการ', 'ค่า'])
        ->and($summary['ตั้งแต่วันที่'])->toBe('2026-06-01')
        ->and($summary['เปิดใหม่'])->toEqual(1)
        ->and($summary['ปิดงาน'])->toEqual(1)
        ->and($summary['สถานะ ปิดแล้ว'])->toEqual(1)
        ->and($summary['เบิกใช้สุทธิ (ชิ้น)'])->toEqual(1)
        ->and($summary['แบบประเมินที่ส่ง'])->toEqual(1)
        ->and($sheets[1])->toEqual([['ลูกค้า', 'ใบงาน'], ['Acme', 1]])
        ->and($sheets[2][1][0])->toBe('Tech One')
        ->and($sheets[3][1])->toEqual(['RAM', 'Memory', 1, 'pcs', 500]);
});
