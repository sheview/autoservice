<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Links that leave the screen — printed on a label, put in a QR code, sent to a customer — are built
 * on APP_URL, not on the address the page happened to be opened with (localhost, a LAN IP), so they
 * open from anyone's phone.
 */
class PublicUrl
{
    /**
     * @param  array<string, mixed>|string|int  $parameters
     */
    public static function route(string $name, mixed $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($name, $parameters, false);
    }

    /**
     * A customer's link into one company: on its own host, or under /t/{company code} on the shared
     * one (config tenancy.public_links). $path starts with a slash, e.g. "/track/{token}".
     */
    public static function forTenant(Tenant $tenant, string $path): string
    {
        $app = parse_url((string) config('app.url'));
        $scheme = $app['scheme'] ?? 'https';
        $port = isset($app['port']) ? ':'.$app['port'] : '';
        $central = config('tenancy.central_domains')[0] ?? ($app['host'] ?? 'localhost');

        return config('tenancy.public_links') === 'subdomain'
            ? "{$scheme}://{$tenant->subdomain}.{$central}{$port}{$path}"
            : rtrim((string) config('app.url'), '/')."/t/{$tenant->company_code}{$path}";
    }
}
