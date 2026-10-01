<?php

use App\Modules\Contract\Models\Customer;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-06-15 10:00');
    $this->admin = userWithRole('admin_company');
    $this->customer = createCustomer();

    // The company paid for the first half of 2026.
    $this->subscribe = fn (?string $starts, ?string $ends) => $this->tenant->update([
        'subscription_starts_on' => $starts, 'subscription_ends_on' => $ends,
    ]);
    $this->createCustomer = fn () => $this->actingAs($this->admin)->post('/customers', ['code' => 'NEW'.random_int(1, 9999), 'name' => 'New customer']);
});

it('works out the state of a subscription from its dates', function () {
    $tenant = new Tenant(['subscription_starts_on' => '2026-01-01', 'subscription_ends_on' => '2026-06-30']);
    $on = fn (string $day) => Subscription::of($tenant, CarbonImmutable::parse($day));

    expect($on('2025-12-31')['state'])->toBe('not_started')
        ->and($on('2026-01-01')['state'])->toBe('active')
        ->and($on('2026-05-30'))->toMatchArray(['state' => 'active', 'days_left' => 31])
        // warned from 30 days before the last day
        ->and($on('2026-05-31'))->toMatchArray(['state' => 'expiring', 'days_left' => 30, 'read_only' => false])
        ->and($on('2026-06-30'))->toMatchArray(['state' => 'expiring', 'days_left' => 0])
        ->and($on('2026-07-01'))->toMatchArray(['state' => 'grace', 'read_only' => true, 'read_only_until' => '2026-07-30'])
        ->and($on('2026-07-30')['state'])->toBe('grace')
        ->and($on('2026-07-31'))->toMatchArray(['state' => 'locked', 'locked' => true])
        ->and(Subscription::of(new Tenant)['state'])->toBe('unlimited')
        ->and(Subscription::of(new Tenant(['is_platform' => true, 'subscription_ends_on' => '2020-01-01']))['state'])->toBe('unlimited');
});

it('leaves a company without dates, or in its period, alone', function () {
    ($this->createCustomer)()->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('subscription.state', 'unlimited'));

    ($this->subscribe)('2026-01-01', '2026-12-31');
    ($this->createCustomer)()->assertSessionMissing('error');
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('subscription.state', 'active'));
});

it('warns the staff in the last 30 days, and not the customer accounts', function () {
    ($this->subscribe)('2026-01-01', '2026-06-30');

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('subscription.state', 'expiring')
        ->where('subscription.days_left', 15)
        ->where('subscription.ends_on', '2026-06-30'));
    ($this->createCustomer)()->assertSessionMissing('error');

    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('subscription', null));
});

it('lets the company look but not change anything for 30 days after the end', function () {
    ($this->subscribe)('2026-01-01', '2026-06-10');

    $this->actingAs($this->admin)->get('/customers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('subscription.state', 'grace')->where('subscription.read_only_until', '2026-07-10'));

    ($this->createCustomer)()->assertRedirect()->assertSessionHas('error', fn ($message) => str_contains($message, '2026-07-10'));
    $this->actingAs($this->admin)->delete("/customers/{$this->customer->id}")->assertSessionHas('error');
    $this->actingAs($this->admin)->post('/logout')->assertRedirect('/');

    expect(Customer::count())->toBe(1);
});

it('does the same before the start date', function () {
    ($this->subscribe)('2026-07-01', '2027-06-30');

    $this->actingAs($this->admin)->get('/customers')->assertOk();
    ($this->createCustomer)()->assertSessionHas('error', fn ($message) => str_contains($message, '2026-07-01'));
});

it('locks the company out once the read-only period is over', function () {
    ($this->subscribe)('2025-01-01', '2026-05-01');

    $this->actingAs($this->admin)->get('/customers')
        ->assertStatus(403)
        ->assertInertia(fn (Assert $page) => $page->component('Subscription/Locked')->where('subscription.state', 'locked'));
    ($this->createCustomer)()->assertStatus(403);
    // signing out still works
    $this->actingAs($this->admin)->post('/logout')->assertRedirect('/');
});

it('lets the superadmin in to fix things, and central staff look only', function () {
    ($this->subscribe)('2025-01-01', '2026-05-01');
    $superadmin = createSuperadmin();
    $central = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, [], $superadmin->tenant);

    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($superadmin)->get('/customers')->assertOk();
    $this->actingAs($superadmin)->post('/customers', ['code' => 'FIX', 'name' => 'Fixed'])->assertSessionMissing('error');

    $this->actingAs($central)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($central)->get('/customers')->assertOk();
    $this->actingAs($central)->post('/tickets', ['title' => 'x', 'priority' => 'low', 'source' => 'phone'])->assertSessionHas('error');
    $this->actingAs($central)->delete('/platform/impersonation')->assertRedirect();
});
