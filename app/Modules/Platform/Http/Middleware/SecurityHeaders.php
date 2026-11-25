<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser's safety settings on every answer:
 *   - no other site may show these pages in a frame (clickjacking): X-Frame-Options and CSP frame-ancestors;
 *   - a file is taken as the type it is sent as (X-Content-Type-Options);
 *   - other sites are told only where a link came from, not the page's address (Referrer-Policy);
 *   - the camera and location only for this site (photos, PM arrival), no microphone or payment;
 *   - over HTTPS, the browser keeps to HTTPS (HSTS, a year; subdomains are left to their own);
 *   - the PHP version is not told.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
