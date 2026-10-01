<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Support\SessionTimeout;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the platform's idle timeout to the session. Runs before StartSession, which reads
 * session.lifetime both to drop an expired session and to set the cookie's expiry.
 */
class ApplySessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.lifetime' => SessionTimeout::minutes()]);

        return $next($request);
    }
}
