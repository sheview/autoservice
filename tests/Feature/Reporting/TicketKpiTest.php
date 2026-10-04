<?php

use App\Modules\Service\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-06-15 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->other = userWithRole('technician', ['name' => 'Tech Two']);

    // Fixed by $tech: resolved at $at, due at $due.
    $this->fixed = function (string $at, ?string $due, ?string $opened = null) {
        $ticket = openTicket($this->admin);
        $ticket->forceFill([
            'assignee_id' => $this->tech->id,
            'status' => Ticket::STATUS_RESOLVED,
            'created_at' => $opened ?? '2026-03-01 08:00',
            'resolved_at' => $at,
            'resolve_due_at' => $due,
        ])->save();

        return $ticket;
    };
});

it('counts tickets opened and fixed per person for a year, with on-time rate and time to fix', function () {
    ($this->fixed)('2026-03-01 12:00', '2026-03-01 16:00'); // on time, 4 h
    ($this->fixed)('2026-03-02 08:00', '2026-03-01 16:00'); // late, 24 h
    ($this->fixed)('2025-12-31 12:00', null, '2025-12-30 08:00'); // last year
    $cancelled = openTicket($this->admin);
    $cancelled->forceFill(['assignee_id' => $this->tech->id, 'status' => Ticket::STATUS_CANCELLED, 'resolved_at' => '2026-03-03 08:00'])->save();
    openTicket($this->tech);

    $this->actingAs($this->admin)->get('/summary/people/kpi?year=2026')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reporting/People/Kpi')
            ->where('people.total', 2)
            ->where('people.data.0.name', 'Tech One')
            ->where('people.data.0.resolved', 2)
            ->where('people.data.0.opened', 1)
            ->where('people.data.0.on_time_rate', 50)
            ->where('people.data.0.avg_hours', 14)
            ->where('people.data.1.name', 'Admin')
            ->where('people.data.1.opened', 3)); // the one dated 2025 is not counted

    $this->actingAs($this->admin)->get("/summary/people/view?user={$this->tech->id}&year=2026")
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpi.year', 2026)
            ->where('kpi.months.2.resolved', 2)
            ->where('kpi.months.5.opened', 1));
});

it('shows a technician only their own figures', function () {
    ($this->fixed)('2026-03-01 12:00', null);
    openTicket($this->other);

    $this->actingAs($this->tech)->get('/summary/people/kpi')
        ->assertInertia(fn (Assert $page) => $page->where('people.total', 1)->where('people.data.0.name', 'Tech One'));
});
