<?php

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
 * Findings of the security test (2026-11-25): a forged Host header must not reach a page (nor the
 * links e-mailed from it, e.g. a password reset), every answer carries the browser's safety
 * headers, the session cookie is HTTPS-only on an HTTPS site, and the reset page speaks Thai.
 */

beforeEach(function () {
    config(['app.url' => 'http://localhost', 'tenancy.central_domains' => ['localhost']]);
});

it('answers only for the system\'s own hosts and company subdomains', function () {
    $this->get('http://localhost/login')->assertOk();
    $this->get('http://default.localhost/login')->assertOk();

    foreach (['http://evil.example/login', 'http://localhost.evil.example/login', 'http://evillocalhost/login'] as $url) {
        $this->get($url)->assertStatus(400)->assertSee('Unknown host');
    }
});

it('never e-mails a reset link to a host the attacker named', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'victim@example.com']);

    $this->post('http://evil.example/forgot-password', ['email' => 'victim@example.com'])->assertStatus(400);
    Notification::assertNothingSent();

    $this->post('http://localhost/forgot-password', ['email' => 'victim@example.com'])
        ->assertSessionHas('status', __('passwords.sent'));
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        expect($notification->toMail($user)->actionUrl)->toStartWith('http://localhost/reset-password/');

        return true;
    });
});

it('tells the same in Thai whether or not the e-mail has an account', function () {
    Notification::fake();
    $this->post('http://localhost/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', __('passwords.sent'));
    expect(__('passwords.sent'))->toContain('ถ้าอีเมลนี้มีอยู่ในระบบ');
});

it('sends the browser\'s safety headers, and not the PHP version', function () {
    $response = $this->get('http://localhost/login');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('X-Powered-By')
        ->assertHeaderMissing('Strict-Transport-Security'); // not over plain HTTP
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)')->toContain('microphone=()');

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('keeps the session cookie to HTTPS when the site is on HTTPS', function () {
    $secure = fn (string $appUrl, ?string $env = null) => (function () use ($appUrl, $env) {
        putenv("APP_URL={$appUrl}");
        $env === null ? putenv('SESSION_SECURE_COOKIE') : putenv("SESSION_SECURE_COOKIE={$env}");
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $appUrl;
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
        if ($env !== null) {
            $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = $env;
        }

        return (require config_path('session.php'))['secure'];
    })();

    $original = [getenv('APP_URL'), getenv('SESSION_SECURE_COOKIE'), $_ENV, $_SERVER];
    try {
        expect($secure('https://itsm.example.com'))->toBeTrue()
            ->and($secure('http://127.0.0.1:8000'))->toBeFalse()
            ->and($secure('https://itsm.example.com', 'false'))->toBeFalse();
    } finally {
        [$appUrl, $cookie, $_ENV, $_SERVER] = $original;
        $appUrl === false ? putenv('APP_URL') : putenv("APP_URL={$appUrl}");
        $cookie === false ? putenv('SESSION_SECURE_COOKIE') : putenv("SESSION_SECURE_COOKIE={$cookie}");
    }
});
