<?php

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/*
 * A form sent from a page opened before its session ended (left open too long, or loaded before a
 * deploy): back to that page — it loads with a new token — with a message, not Laravel's bare
 * "419 PAGE EXPIRED". The CSRF check is off while tests run, so the route throws what it throws.
 */

beforeEach(function () {
    Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException);
});

it('sends an expired form back to its page with a message to try again', function () {
    $this->post('/_test/expired', ['email' => 'somchai@example.com'], ['Referer' => url('/login')])
        ->assertRedirect(url('/login'))
        ->assertSessionHas('status', 'หน้านี้เปิดค้างไว้นานจนหมดเวลา กรุณาลองอีกครั้ง')
        ->assertSessionHas('error', 'หน้านี้เปิดค้างไว้นานจนหมดเวลา กรุณาลองอีกครั้ง');
});

it('does the same for an Inertia request', function () {
    $this->post('/_test/expired', [], ['Referer' => url('/login'), 'X-Inertia' => 'true'])
        ->assertRedirect(url('/login'));
});

it('still answers 419 to a JSON request', function () {
    $this->postJson('/_test/expired')->assertStatus(419);
});
