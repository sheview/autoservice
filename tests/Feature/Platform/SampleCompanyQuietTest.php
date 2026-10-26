<?php

use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Actions\SendAlert;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\Platform\Notifications\AlertMail;
use App\Modules\Platform\Support\AlertSender;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/** Makes the test's company the training company. */
function becomeSampleCompany(Tenant $tenant): void
{
    $tenant->forceFill(['is_sample' => true])->save();
    app(TenantContext::class)->set($tenant);
}

/** Sends one mail in the current company and says whether it really went out. */
function mailGoesOut(): bool
{
    $sent = false;
    Event::listen(MessageSent::class, function () use (&$sent) {
        $sent = true;
    });
    Notification::route('mail', 'someone@example.com')->notifyNow(new AlertMail('หัวข้อ', 'ข้อความ', null));

    return $sent;
}

it('sends no e-mail from the training company', function () {
    becomeSampleCompany($this->tenant);

    expect(mailGoesOut())->toBeFalse();
});

it('still sends e-mail from other companies', function () {
    expect(mailGoesOut())->toBeTrue();
});

it('queues no LINE or Telegram alert for the training company', function () {
    Bus::fake([DeliverAlert::class]);
    app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => ['ticket_resolved'],
        'telegram' => ['enabled' => true, 'chat_id' => '-100555', 'token' => '123:telegram-secret'],
    ]);
    $alert = fn () => app(SendAlert::class)->handle('ticket_resolved', ['no' => 'T-1', 'title' => 'x', 'actor' => 'x']);

    $alert();
    Bus::assertDispatchedTimes(DeliverAlert::class, 1);

    becomeSampleCompany($this->tenant->fresh());
    $alert();
    Bus::assertDispatchedTimes(DeliverAlert::class, 1);
});

it('refuses to send an alert from the training company, saying why', function () {
    Http::fake();
    becomeSampleCompany($this->tenant);

    app(AlertSender::class)->send('telegram', ['token' => 'x', 'chat_id' => '1'], 'หัวข้อ', 'ข้อความ');
})->throws(RuntimeException::class, 'บริษัทตัวอย่าง');

it('tells the frontend which company is the training company', function () {
    $admin = userWithRole('admin_company');

    $this->actingAs($admin)->get('/settings/alerts')->assertInertia(fn (Assert $page) => $page->where('tenant.is_sample', false));

    becomeSampleCompany($this->tenant);
    $this->actingAs($admin)->get('/settings/alerts')->assertInertia(fn (Assert $page) => $page->where('tenant.is_sample', true));
});

it('runs every deploy step and makes the training company', function () {
    $this->artisan('platform:deploy')->assertSuccessful();

    expect(Tenant::query()->where('subdomain', 'sample')->value('is_sample'))->toBeTrue();
});

it('deploys without the training company when asked', function () {
    $this->artisan('platform:deploy --without-sample')->assertSuccessful();

    expect(Tenant::query()->where('subdomain', 'sample')->exists())->toBeFalse();
});
