<?php

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Notifications\AlertMail;
use App\Modules\Platform\Support\AlertSender;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Somsak Tech']);
    $this->settings = [
        'events' => ['checkout_requested', 'checkout_approved', 'ticket_opened', 'asset_in_repair'],
        'line' => ['enabled' => true, 'to' => 'Cgroup123', 'token' => 'line-secret-token'],
        'telegram' => ['enabled' => true, 'chat_id' => '-100555', 'token' => '123:telegram-secret'],
        'mail' => ['enabled' => true, 'recipients' => ['Boss@Example.com', '', 'ops@example.com']],
    ];
    $this->asset = createAsset(createAssetCategory(), ['asset_code' => 'SW-001', 'name' => 'Core switch', 'status' => Asset::STATUS_SPARE]);
});

it('lets the company admin set channels and events, keeping the tokens secret', function () {
    $this->actingAs($this->admin)->get('/settings/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Platform/AlertSettings')
        ->where('settings.line.token_set', false));

    $this->actingAs($this->admin)->put('/settings/alerts', $this->settings)->assertSessionHasNoErrors();

    $stored = $this->tenant->fresh()->settings['alerts'];
    expect($stored['line']['token'])->not->toBe('line-secret-token')
        ->and(decrypt($stored['line']['token'], false))->toBe('line-secret-token')
        ->and($stored['mail']['recipients'])->toBe(['boss@example.com', 'ops@example.com']);

    // the page knows a token is set, never what it is
    $response = $this->actingAs($this->admin)->get('/settings/alerts');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('settings.line.token_set', true)
        ->where('settings.line.to', 'Cgroup123')
        ->where('settings.events', ['checkout_requested', 'checkout_approved', 'ticket_opened', 'asset_in_repair']));
    expect($response->getContent())->not->toContain('line-secret-token')->not->toContain('telegram-secret');

    // an empty token keeps the saved one; "remove" clears it
    $this->actingAs($this->admin)->put('/settings/alerts', [...$this->settings, 'line' => ['enabled' => true, 'to' => 'Cgroup123', 'token' => '']]);
    expect(decrypt($this->tenant->fresh()->settings['alerts']['line']['token'], false))->toBe('line-secret-token');
    $this->actingAs($this->admin)->put('/settings/alerts', [...$this->settings, 'telegram' => ['enabled' => false, 'chat_id' => '', 'remove_token' => true]]);
    expect($this->tenant->fresh()->settings['alerts']['telegram']['token'])->toBeNull();

    // the log says what changed, without the tokens
    $logged = json_encode(Activity::where('event', 'alert_settings_updated')->get()->pluck('properties'));
    expect($logged)->not->toContain('secret');
});

it('checks the settings', function () {
    $this->actingAs($this->admin)->put('/settings/alerts', [
        'events' => ['nope'],
        'line' => ['enabled' => true, 'to' => ''],
        'mail' => ['enabled' => true, 'recipients' => ['not-an-email']],
    ])->assertSessionHasErrors(['events.0', 'line.to', 'mail.recipients.0']);
});

it('is for the company admin only', function () {
    foreach ([$this->tech, userWithRole('helpdesk'), userWithRole('user')] as $user) {
        $this->actingAs($user)->get('/settings/alerts')->assertForbidden();
        $this->actingAs($user)->put('/settings/alerts', $this->settings)->assertForbidden();
        $this->actingAs($user)->post('/settings/alerts/test', ['channel' => 'line'])->assertForbidden();
    }
});

it('alerts on LINE, Telegram and e-mail when an asset is asked for and approved', function () {
    Http::fake(['api.line.me/*' => Http::response(['sentMessages' => []]), 'api.telegram.org/*' => Http::response(['ok' => true])]);
    Notification::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, $this->settings);

    // a technician asks for themself (asset-checkouts.request)
    $this->actingAs($this->tech)->post("/assets/{$this->asset->ulid}/checkouts", [
        'type' => 'loan', 'due_on' => '2026-10-10',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.line.me/v2/bot/message/push'
        && $request->hasHeader('Authorization', 'Bearer line-secret-token')
        && $request['to'] === 'Cgroup123'
        && str_contains($request['messages'][0]['text'], 'มีคำขอยืมใหม่')
        && str_contains($request['messages'][0]['text'], 'SW-001 Core switch')
        && str_contains($request['messages'][0]['text'], 'Somsak Tech'));
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.telegram.org/bot123:telegram-secret/sendMessage'
        && $request['chat_id'] === '-100555');
    Notification::assertSentTo(new AnonymousNotifiable, AlertMail::class, fn (AlertMail $mail, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['boss@example.com', 'ops@example.com']
        && str_contains($mail->title, 'มีคำขอยืมใหม่'));

    $this->actingAs($this->admin)->post('/asset-checkouts/'.AssetCheckout::sole()->ulid.'/approve')->assertSessionHasNoErrors();
    Http::assertSentCount(4);

    // returned is not one of the events chosen
    $this->actingAs($this->admin)->post('/asset-checkouts/'.AssetCheckout::sole()->ulid.'/return')->assertSessionHasNoErrors();
    Http::assertSentCount(4);
});

it('alerts when a repair ticket is opened and when an asset is sent for repair', function () {
    Queue::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, $this->settings);

    openTicket(userWithRole('helpdesk'), ['title' => 'Printer down']);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'ticket_opened' && str_contains($job->body, 'Printer down'));

    app(SaveAsset::class)->handle($this->asset, ['category_id' => $this->asset->category_id, 'name' => 'Core switch', 'status' => Asset::STATUS_IN_REPAIR]);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'asset_in_repair' && str_contains($job->title, 'SW-001'));
});

it('sends nothing for events or channels that are off, and a failing channel does not stop the others', function () {
    Queue::fake();
    openTicket(userWithRole('helpdesk'));
    Queue::assertNotPushed(DeliverAlert::class);

    app(SaveAlertSettings::class)->handle($this->tenant, [...$this->settings, 'line' => ['enabled' => false], 'mail' => ['enabled' => false]]);
    Queue::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
    (new DeliverAlert('ticket_opened', 'T', 'B'))->handle(app(TenantContext::class), app(AlertSender::class));
    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'line.me'));
});

it('sends a test message and shows what the service said', function () {
    app(SaveAlertSettings::class)->handle($this->tenant, $this->settings);
    Http::fake(['api.line.me/*' => Http::response(['message' => 'Authentication failed'], 401), 'api.telegram.org/*' => Http::response(['ok' => true])]);

    $this->actingAs($this->admin)->post('/settings/alerts/test', ['channel' => 'telegram'])->assertSessionHas('success');
    $this->actingAs($this->admin)->post('/settings/alerts/test', ['channel' => 'line'])
        ->assertSessionHas('error', fn (string $error) => str_contains($error, 'Authentication failed'));

    app(SaveAlertSettings::class)->handle($this->tenant, [...$this->settings, 'mail' => ['enabled' => false]]);
    $this->actingAs($this->admin)->post('/settings/alerts/test', ['channel' => 'mail'])->assertSessionHas('error');
});

it('keeps each company to its own settings', function () {
    app(SaveAlertSettings::class)->handle($this->tenant, $this->settings);
    $other = createTenant('other');

    Queue::fake();
    asTenant($other, fn () => openTicket(userWithRole('helpdesk')));
    Queue::assertNotPushed(DeliverAlert::class);

    expect($other->fresh()->settings['alerts'] ?? null)->toBeNull();
});
