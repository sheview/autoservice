<?php

use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Platform\Support\Turnstile;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    $this->asset = createAsset(createAssetCategory());
    $this->key = $this->asset->fresh()->public_key;
    $this->reportUrl = "/t/001/q/{$this->asset->asset_code}/report?k={$this->key}";
    $this->form = ['symptoms' => ['เปิดไม่ติด'], 'name' => 'Khun A', 'phone' => '0812345678'];
});

it('limits how often one address may report', function () {
    foreach (range(1, 3) as $n) {
        $this->post($this->reportUrl, $this->form)->assertRedirect();
    }
    $this->post($this->reportUrl, $this->form)->assertStatus(429);
});

it('limits how fast one address may search for tickets', function () {
    foreach (range(1, 10) as $n) {
        $this->get('/track?company=default&q=TK-2569-0000'.$n)->assertOk();
    }
    $this->get('/track?company=default&q=TK-2569-00011')->assertStatus(429);
});

it('asks for the CAPTCHA on every report once keys are set', function () {
    config(['services.turnstile' => ['site_key' => 'site', 'secret_key' => 'secret']]);
    Http::fake([Turnstile::VERIFY_URL => Http::sequence()->push(['success' => false])->push(['success' => true])]);

    $this->get("/t/001/q/{$this->asset->asset_code}?k={$this->key}")->assertInertia(fn (Assert $page) => $page->where('captcha', 'site'));

    $this->post($this->reportUrl, $this->form)->assertSessionHasErrors('captcha');                                   // no token
    $this->post($this->reportUrl, $this->form + ['cf-turnstile-response' => 'bad'])->assertSessionHasErrors('captcha'); // refused
    expect(Ticket::count())->toBe(0);

    $this->post($this->reportUrl, $this->form + ['cf-turnstile-response' => 'good'])->assertSessionHasNoErrors();
    expect(Ticket::count())->toBe(1);
    Http::assertSent(fn ($request) => $request['secret'] === 'secret' && $request['response'] === 'good');
});

it('asks for the CAPTCHA after a few misses, and tells the admin about many', function () {
    config(['services.turnstile' => ['site_key' => 'site', 'secret_key' => 'secret']]);
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => true])]);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, PublicLookupGuard::CAPTCHA_AFTER) as $n) {
        $this->get("/track?company=default&q=TK-2569-9999{$n}")->assertInertia(fn (Assert $page) => $page->where('results', []));
    }
    // Now: no search without the CAPTCHA.
    $this->get('/track?company=default&q=TK-2569-99990')->assertInertia(fn (Assert $page) => $page
        ->where('captcha', 'site')->where('captchaFailed', true)->where('searched', false));
    $this->get('/track?company=default&q=TK-2569-99990&cf-turnstile-response=ok')->assertInertia(fn (Assert $page) => $page
        ->where('captchaFailed', false)->where('searched', true));

    foreach (range(PublicLookupGuard::CAPTCHA_AFTER + 2, PublicLookupGuard::REPORT_AFTER + 3) as $n) {
        $this->get("/track?company=default&q=TK-2569-8{$n}&cf-turnstile-response=ok");
    }
    // One line in the company's activity log for its admin, not one per miss.
    expect(Activity::where('event', 'public_lookup_misses')->count())->toBe(1)
        ->and(Activity::where('event', 'public_lookup_misses')->first()->properties['ip'])->toBe('127.0.0.1');
});
