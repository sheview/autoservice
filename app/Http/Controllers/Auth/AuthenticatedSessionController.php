<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // A platform admin whose session ran out while viewing as a company is no longer viewing as
        // it: that company's page would be "not found", so they start from the home page instead.
        $intended = $request->session()->get('url.intended');
        if ($intended !== null && $request->user()->tenant?->is_platform
            && ! str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/platform')) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The browser keeps every page seen in its history (encrypted: EncryptHistory). Clearing it
        // means Back after signing out asks the server again — the login page — instead of showing
        // the company's pages from memory. Set after invalidate(), which would drop it.
        Inertia::clearHistory();

        // Straight to the login page: by way of "/" the message would be used up on that redirect.
        return redirect()->route('login')->with('status', __('ui.logged_out'));
    }
}
