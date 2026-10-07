<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\CrossTenant\IdentityLookup;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('logs a user in from the central domain', function () {
    $user = userWithRole('user');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('does not log in an inactive user', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out a user who was deactivated while logged in', function () {
    $user = User::factory()->create();
    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('only logs in users of the tenant on a tenant subdomain', function () {
    createTenant('other');
    $user = User::factory()->create(); // tenant "default"

    $this->post('http://other.localhost/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('http://default.localhost/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('really changes the password on reset (not silently blocked by RLS)', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect(Hash::check('New-password-123', $user->fresh()->password))->toBeTrue();
});

it('hides users of every tenant when no tenant is set, except to the login lookup', function () {
    $user = User::factory()->create(['email' => 'somchai@example.com']);
    app(TenantContext::class)->forget();

    expect(User::count())->toBe(0)
        // the login finds the user before any tenant is known (IdentityLookup)
        ->and(Auth::getProvider()->retrieveByCredentials(['email' => 'somchai@example.com'])?->is($user))->toBeTrue()
        ->and(User::count())->toBe(0);
});
