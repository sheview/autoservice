<?php

use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\SessionTimeout;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin();
    $this->platform = $this->superadmin->tenant;
});

it('shows the idle timeout to the superadmin, SESSION_LIFETIME until it is set', function () {
    $this->actingAs($this->superadmin)->get('/platform/settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Platform/Settings')
            ->where('sessionTimeout', config('session.lifetime'))
            ->where('min', SessionTimeout::MIN)
            ->where('max', SessionTimeout::MAX));
});

it('lets the superadmin set the idle timeout and logs who changed it', function () {
    $this->actingAs($this->superadmin)->put('/platform/settings', ['session_timeout_minutes' => 30])
        ->assertRedirect(route('platform.settings.edit'))->assertSessionHasNoErrors();

    expect($this->platform->fresh()->settings[SessionTimeout::SETTING])->toBe(30)
        ->and(SessionTimeout::minutes())->toBe(30);

    $log = asTenant($this->platform, fn () => Activity::where('event', 'session_timeout_updated')->sole());
    expect($log->causer_id)->toBe($this->superadmin->id)
        ->and($log->properties['attributes'][SessionTimeout::SETTING])->toBe(30);
});

it('keeps the idle timeout within limits', function (mixed $minutes) {
    $this->actingAs($this->superadmin)->put('/platform/settings', ['session_timeout_minutes' => $minutes])
        ->assertSessionHasErrors('session_timeout_minutes');

    expect($this->platform->fresh()->settings)->not->toHaveKey(SessionTimeout::SETTING);
})->with([SessionTimeout::MIN - 1, SessionTimeout::MAX + 1, 'abc', null]);

it('applies the idle timeout to every session', function () {
    $this->actingAs($this->superadmin)->put('/platform/settings', ['session_timeout_minutes' => 15]);

    $user = userWithRole('user');
    $response = $this->actingAs($user)->get('/dashboard')->assertOk();

    // StartSession read the setting: the session cookie lives 15 minutes from now.
    expect(config('session.lifetime'))->toBe(15)
        ->and($response->getCookie(config('session.cookie'), decrypt: false)->getExpiresTime())
        ->toBeBetween(now()->addMinutes(15)->timestamp - 5, now()->addMinutes(15)->timestamp + 5);
});

it('lets nobody else change the idle timeout', function () {
    $companyAdmin = userWithRole('admin_company');
    $centralHelpdesk = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, [], $this->platform);

    foreach ([$companyAdmin, $centralHelpdesk] as $user) {
        $this->actingAs($user)->get('/platform/settings')->assertForbidden();
        $this->actingAs($user)->put('/platform/settings', ['session_timeout_minutes' => 30])->assertForbidden();
    }

    // nor the superadmin while working inside a company
    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($this->superadmin)->get('/platform/settings')->assertForbidden();

    expect($this->platform->fresh()->settings)->not->toHaveKey(SessionTimeout::SETTING);
});
