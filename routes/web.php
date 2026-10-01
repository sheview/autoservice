<?php

use Illuminate\Support\Facades\Route;

// No public landing page: straight to the app (or the sign-in page).
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

// The dashboard and every other page live in app/Modules/*/routes/web.php.

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
