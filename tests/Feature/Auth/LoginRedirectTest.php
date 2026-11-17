<?php

use App\Modules\Identity\Models\User;

/*
 * Where signing in leads: a wrong password always back to the login page with the message, and
 * after signing in the page the user was sent away from, never a file a page was loading (a photo,
 * the logo) when the session ran out.
 */

beforeEach(function () {
    $this->user = userWithRole('admin_company', ['email' => 'somchai@example.com']);
    // what a browser sends for an <img>, and for a page
    $this->image = ['Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8'];
    $this->page = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
    auth()->logout();
});

it('says the password is wrong on the login page, whatever was loaded last', function () {
    // an old tab loads a photo after the session ran out
    $this->get('/company/logo', $this->image)->assertStatus(401);

    // no Referer: "back" would be the last URL the session saw
    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'wrong'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
    $this->assertGuest();

    $this->post('/login', ['email' => 'not-an-email', 'password' => ''])->assertRedirect(route('login'));
});

it('goes to the home page after signing in, not to a file loaded while signed out', function () {
    $this->get('/company/logo', $this->image);

    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs(User::where('email', 'somchai@example.com')->first());
});

it('sends a platform admin whose session ran out while viewing as a company to the home page', function () {
    $superadmin = createSuperadmin(['email' => 'root@platform.test']);
    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->user->tenant->ulid}");
    $this->get('/tickets')->assertOk();

    // the session runs out: viewing as the company ends with it
    auth()->logout();
    session()->invalidate();
    $this->get('/tickets', $this->page)->assertRedirect(route('login'));

    $this->post('/login', ['email' => 'root@platform.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard', absolute: false))->assertOk();

    // a platform page is still come back to
    auth()->logout();
    $this->get('/platform/settings', $this->page)->assertRedirect(route('login'));
    $this->post('/login', ['email' => 'root@platform.test', 'password' => 'password'])->assertRedirect('/platform/settings');
});

it('still comes back to the page the user was sent away from', function () {
    $this->get('/assets?search=PC', $this->page)->assertRedirect(route('login'));

    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'password'])
        ->assertRedirect('/assets?search=PC');
});
