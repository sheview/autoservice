<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\EndSsoSessions;
use App\Modules\Identity\Actions\SignInWithSso;
use App\Modules\Identity\Support\Sso;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Sign in with SSO": off to Keycloak, and back.
 *
 * The callback may be on the central domain while the login started on a company's subdomain
 * (one redirect URI in Keycloak, the session shared by SESSION_DOMAIN): where it started — the
 * company to check against and the page to go back to — is kept in the session meanwhile.
 */
class SsoController extends Controller
{
    private const FROM_TENANT = 'sso.tenant_id';

    private const FROM_HOME = 'sso.home';

    public function redirect(Request $request, TenantContext $tenants): SymfonyRedirect
    {
        abort_unless(Sso::enabled(), 404);

        $request->session()->put(self::FROM_TENANT, $tenants->id());
        $request->session()->put(self::FROM_HOME, route('dashboard'));

        return Sso::driver()->redirect();
    }

    public function callback(Request $request, SignInWithSso $signIn): RedirectResponse
    {
        abort_unless(Sso::enabled(), 404);

        $tenantId = $request->session()->pull(self::FROM_TENANT);
        $home = $request->session()->pull(self::FROM_HOME) ?? route('dashboard');

        try {
            // Also when the person cancelled at Keycloak, or came back with an old or forged state.
            $ssoUser = Sso::driver()->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => __('auth.sso_failed')]);
        }

        $user = $signIn->handle($ssoUser, $tenantId);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put([
            Sso::ID_TOKEN => $ssoUser->idToken ?? null,
            Sso::SID => $ssoUser->sid ?? null,
            Sso::SUB => (string) $ssoUser->getId(),
            Sso::SIGNED_IN_AT => time(),
        ]);

        return redirect()->intended($home);
    }

    /** Keycloak, server to server: a session ended there (EndSsoSessions). */
    public function backchannelLogout(Request $request, EndSsoSessions $endSessions): Response
    {
        abort_unless(Sso::enabled(), 404);

        $ended = $endSessions->handle((string) $request->input('logout_token'));

        // No caching of the answer, as the back-channel logout spec asks.
        return response('', $ended ? 200 : 400)->header('Cache-Control', 'no-store');
    }
}
