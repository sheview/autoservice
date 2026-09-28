<?php

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Notifications\ContractsExpiringNotification;
use App\Modules\Platform\Support\Modules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-06-15 07:00');
});

it('e-mails users with contract.update once per contract, in every tenant', function () {
    $admin = userWithRole('admin_company');
    $helpdesk = userWithRole('helpdesk'); // contract.view only
    $customer = createCustomer();
    $soon = createContract($customer, ['contract_no' => 'SOON', 'ends_on' => '2026-07-31', 'notify_days_before' => 60]);
    createContract($customer, ['contract_no' => 'LATER', 'ends_on' => '2026-12-31', 'notify_days_before' => 60]);
    createContract($customer, ['contract_no' => 'ENDED', 'ends_on' => '2026-06-14']);
    createContract($customer, ['contract_no' => 'DRAFT', 'status' => 'draft', 'ends_on' => '2026-07-01']);

    $other = createTenant('other');
    [$otherAdmin, $otherContract] = asTenant($other, fn () => [
        userWithRole('admin_company'),
        createContract(createCustomer(), ['contract_no' => 'OTHER', 'ends_on' => '2026-06-20', 'notify_days_before' => 30]),
    ]);

    $this->artisan('contracts:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($admin, ContractsExpiringNotification::class,
        fn ($n) => $n->contracts->pluck('contract_no')->all() === ['SOON']);
    Notification::assertNotSentTo($helpdesk, ContractsExpiringNotification::class);
    Notification::assertSentTo($otherAdmin, ContractsExpiringNotification::class,
        fn ($n) => $n->contracts->pluck('contract_no')->all() === ['OTHER']);

    expect($soon->fresh()->expiry_notified_at)->not->toBeNull()
        ->and(asTenant($other, fn () => $otherContract->fresh()->expiry_notified_at))->not->toBeNull();

    // the next day nothing is sent again
    $this->travelTo('2026-06-16 07:00');
    $this->artisan('contracts:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($admin, ContractsExpiringNotification::class, 1);
});

it('writes the end date with the Buddhist year in the e-mail', function () {
    $admin = userWithRole('admin_company', ['name' => 'Somchai']);
    createContract(createCustomer(['name' => 'Acme']), ['contract_no' => 'SOON', 'title' => 'MA ปี 69', 'ends_on' => '2026-07-31']);

    $this->artisan('contracts:notify-expiring');

    Notification::assertSentTo($admin, ContractsExpiringNotification::class, function ($notification) use ($admin) {
        $mail = $notification->toMail($admin);

        return str_contains($mail->subject, '1 ฉบับ')
            && in_array('SOON MA ปี 69 (Acme) สิ้นสุด 31/07/2569 — อีก 46 วัน', $mail->introLines, true);
    });
});

it('skips tenants that switched the contract module off', function () {
    $admin = userWithRole('admin_company');
    createContract(createCustomer(), ['ends_on' => '2026-07-01']);
    Feature::for($this->tenant)->deactivate(Modules::feature('contract'));

    $this->artisan('contracts:notify-expiring');

    Notification::assertNothingSentTo($admin);
    expect(Contract::first()->expiry_notified_at)->toBeNull();
});

it('is on the daily schedule', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'contracts:notify-expiring')))->toBeTrue();
});
