<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Platform\Models\Activity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->customer = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
});

function contractPayload(array $overrides = []): array
{
    return $overrides + [
        'customer_id' => test()->customer->id,
        'contract_no' => 'MA-2026-001',
        'title' => 'MA Network ปี 2569',
        'status' => 'active',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'value' => '120000.50',
        'service_window' => '24x7',
        'pm_interval_months' => 3,
        'notify_days_before' => 45,
        'slas' => [
            'critical' => ['response_hours' => 0.5, 'resolve_hours' => 4],
            'high' => ['response_hours' => 2, 'resolve_hours' => 8],
            'medium' => ['response_hours' => null, 'resolve_hours' => null],
        ],
    ];
}

it('creates a contract with SLA in minutes and value in satang', function () {
    $response = $this->actingAs($this->admin)->post('/contracts', contractPayload());

    $contract = Contract::first();
    $response->assertRedirect(route('contract.contracts.show', $contract))->assertSessionHasNoErrors();

    expect($contract->value)->toBe(12000050)
        ->and($contract->pm_interval_months)->toBe(3)
        ->and($contract->slas()->orderBy('response_minutes')->get(['priority', 'response_minutes', 'resolve_minutes'])->toArray())->toBe([
            ['priority' => 'critical', 'response_minutes' => 30, 'resolve_minutes' => 240],
            ['priority' => 'high', 'response_minutes' => 120, 'resolve_minutes' => 480],
        ]);
});

it('validates dates, SLA pairs and unique numbers', function () {
    createContract($this->customer, ['contract_no' => 'TAKEN']);

    $this->actingAs($this->admin)->post('/contracts', contractPayload([
        'contract_no' => 'TAKEN',
        'ends_on' => '2025-12-31',
        'service_window' => '7x7',
        'pm_interval_months' => 5,
        'slas' => [
            'critical' => ['response_hours' => 4, 'resolve_hours' => 2],
            'low' => ['response_hours' => 1, 'resolve_hours' => null],
        ],
    ]))->assertSessionHasErrors([
        'contract_no', 'ends_on', 'service_window', 'pm_interval_months',
        'slas.critical.resolve_hours', 'slas.low.resolve_hours',
    ]);
});

it('updates the SLA rows and re-arms the expiry e-mail when the end date moves', function () {
    $contract = createContract($this->customer, ['slas' => ['low' => ['response_minutes' => 60, 'resolve_minutes' => 600]]]);
    $contract->update(['expiry_notified_at' => now()]);

    $this->actingAs($this->admin)->put("/contracts/{$contract->id}", contractPayload(['contract_no' => $contract->contract_no]))
        ->assertSessionHasNoErrors();

    $contract->refresh();
    expect($contract->slas()->pluck('priority')->sort()->values()->all())->toBe(['critical', 'high'])
        ->and($contract->expiry_notified_at)->toBeNull();

    $log = Activity::where('subject_type', $contract->getMorphClass())->where('subject_id', $contract->id)->where('event', 'updated')->first();
    expect($log->properties['actor']['name'])->toBe('Admin Here');
});

it('works out the phase of a contract', function () {
    $this->travelTo('2026-06-15');

    $phase = fn (array $attributes) => ContractPhase::of(new Contract($attributes + [
        'status' => 'active', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'notify_days_before' => 60,
    ]));

    expect($phase([]))->toBe('active')
        ->and($phase(['status' => 'draft']))->toBe('draft')
        ->and($phase(['status' => 'cancelled']))->toBe('cancelled')
        ->and($phase(['starts_on' => '2026-07-01']))->toBe('upcoming')
        ->and($phase(['ends_on' => '2026-06-14']))->toBe('expired')
        ->and($phase(['ends_on' => '2026-08-14']))->toBe('expiring')
        ->and($phase(['ends_on' => '2026-06-15']))->toBe('expiring');
});

it('lists contracts with search and the same phase filter', function () {
    $this->travelTo('2026-06-15');
    $other = createCustomer(['code' => 'BETA', 'name' => 'Beta']);

    createContract($this->customer, ['contract_no' => 'RUNNING', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    createContract($this->customer, ['contract_no' => 'SOON', 'starts_on' => '2025-07-01', 'ends_on' => '2026-07-31']);
    createContract($other, ['contract_no' => 'OLD', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    createContract($other, ['contract_no' => 'DRAFT', 'status' => 'draft']);

    $numbers = fn (string $query) => $this->actingAs($this->admin)->get("/contracts?{$query}")
        ->assertInertia(fn (Assert $page) => $page->component('Contract/Contracts/Index'))
        ->viewData('page')['props']['contracts']['data'];

    expect(collect($numbers('phase=active'))->pluck('contract_no')->all())->toBe(['RUNNING'])
        ->and(collect($numbers('phase=expiring'))->pluck('contract_no')->all())->toBe(['SOON'])
        ->and(collect($numbers('phase=expired'))->pluck('contract_no')->all())->toBe(['OLD'])
        ->and(collect($numbers('phase=draft'))->pluck('contract_no')->all())->toBe(['DRAFT'])
        ->and(collect($numbers('search=beta'))->pluck('contract_no')->sort()->values()->all())->toBe(['DRAFT', 'OLD'])
        ->and(collect($numbers("customer_id={$this->customer->id}&sort=contract_no"))->pluck('contract_no')->all())->toBe(['RUNNING', 'SOON'])
        ->and(collect($numbers('phase=expiring'))->first()['phase'])->toBe('expiring');
});

it('shows a contract with its SLA table', function () {
    $contract = createContract($this->customer, ['slas' => ['critical' => ['response_minutes' => 30, 'resolve_minutes' => 240]]]);

    $this->actingAs($this->admin)->get("/contracts/{$contract->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Contract/Contracts/Show')
            ->where('contract.customer.name', 'Acme')
            ->where('contract.slas.0', ['priority' => 'critical', 'response_minutes' => 30, 'resolve_minutes' => 240])
            ->where('contract.slas.3', ['priority' => 'low', 'response_minutes' => null, 'resolve_minutes' => null]));
});

it('soft-deletes a contract', function () {
    $contract = createContract($this->customer);

    $this->actingAs($this->admin)->delete("/contracts/{$contract->id}")->assertRedirect('/contracts');

    expect(Contract::find($contract->id))->toBeNull()
        ->and(Contract::withTrashed()->find($contract->id))->not->toBeNull();
});

it('lets a technician view but not change contracts', function () {
    $technician = userWithRole('technician');
    $contract = createContract($this->customer);
    openTicket($this->admin, ['customer_id' => $this->customer->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get('/contracts')->assertOk();
    $this->actingAs($technician)->get("/contracts/{$contract->id}")->assertOk();
    $this->actingAs($technician)->post('/contracts', contractPayload())->assertForbidden();
    $this->actingAs($technician)->put("/contracts/{$contract->id}", contractPayload())->assertForbidden();
    $this->actingAs($technician)->delete("/contracts/{$contract->id}")->assertForbidden();

    $this->actingAs(userWithRole('user'))->get('/contracts')->assertForbidden();
});
