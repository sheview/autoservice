<?php

namespace App\Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers only for the system's own hosts: a central domain (tenancy.central_domains) or a
 * company's subdomain of one. Links the system makes for e-mails (password reset, a ticket, a
 * survey) are built from the request's host; a request sent with another Host header (a server
 * answering any name) must not make them point elsewhere, where a reset token would be caught.
 *
 * Always on, also locally and in tests (Laravel's TrustHosts is not), so it is tested.
 */
class AllowKnownHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim((string) $request->getHost(), '.'));

        if (! $this->known($host)) {
            return response('Unknown host', 400)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return $next($request);
    }

    private function known(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        foreach ($this->domains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the central domains and the host of APP_URL */
    private function domains(): array
    {
        $domains = array_map(fn ($domain) => strtolower(trim((string) $domain)), (array) config('tenancy.central_domains', []));
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $domains[] = strtolower($appHost);
        }

        return array_values(array_unique(array_filter($domains)));
    }
}
