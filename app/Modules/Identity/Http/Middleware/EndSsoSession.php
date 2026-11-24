<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Support\Sso;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a person whose Keycloak session was ended there (EndSsoSessions): back to the
 * login page, with the same history clearing as signing out here.
 */
class EndSsoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $sid = $session->get(Sso::SID);

        if ($sid !== null && Auth::guard('web')->check() && $this->ended((string) $sid, $session->get(Sso::SUB), (int) $session->get(Sso::SIGNED_IN_AT))) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            Inertia::clearHistory();
            $session->flash('status', __('auth.sso_ended'));

            return redirect()->route('login');
        }

        return $next($request);
    }

    private function ended(string $sid, ?string $sub, int $signedInAt): bool
    {
        if (Cache::has(Sso::ENDED_SID.$sid)) {
            return true;
        }
        $allBefore = $sub !== null ? Cache::get(Sso::ENDED_SUB.$sub) : null;

        return $allBefore !== null && $signedInAt <= (int) $allBefore;
    }
}
