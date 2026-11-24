<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Modules\Identity\Support\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'sso' => Sso::enabled(),
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
    public function destroy(Request $request): SymfonyResponse
    {
        // Signed in with Keycloak: sign out there too, or "Sign in with SSO" would let them straight back in.
        $idToken = $request->session()->get(Sso::ID_TOKEN);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The browser keeps every page seen in its history (encrypted: EncryptHistory). Clearing it
        // means Back after signing out asks the server again — the login page — instead of showing
        // the company's pages from memory. Set after invalidate(), which would drop it.
        Inertia::clearHistory();

        // Straight to the login page: by way of "/" the message would be used up on that redirect.
        $request->session()->flash('status', __('ui.logged_out'));

        if ($idToken !== null && Sso::enabled()) {
            // Keycloak sends them back to the login page; Inertia::location leaves the app for it.
            return Inertia::location(Sso::logoutUrl($idToken));
        }

        return redirect()->route('login');
    }
}
