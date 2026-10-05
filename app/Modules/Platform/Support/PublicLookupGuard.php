<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Watches the public look-ups that find nothing (ticket tracking by number or serial): numbers
 * and serials can be guessed, tokens cannot. After a few misses from one address within the
 * window the CAPTCHA is asked for; after many, the company (or the platform, if no company was
 * named) gets a line in its activity log, once per window, for its admin to see.
 */
class PublicLookupGuard
{
    public const CAPTCHA_AFTER = 5;

    public const REPORT_AFTER = 20;

    public const WINDOW_SECONDS = 600;

    public static function needsCaptcha(?string $ip): bool
    {
        return Turnstile::enabled() && RateLimiter::attempts(self::key($ip)) >= self::CAPTCHA_AFTER;
    }

    public static function missed(?string $ip, ?Tenant $tenant, string $what): void
    {
        RateLimiter::hit(self::key($ip), self::WINDOW_SECONDS);

        if (RateLimiter::attempts(self::key($ip)) === self::REPORT_AFTER) {
            $where = $tenant ?? Tenant::where('is_platform', true)->first();
            if ($where !== null) {
                app(TenantContext::class)->run($where, fn () => activity('security')
                    ->event('public_lookup_misses')
                    ->withProperties(['ip' => $ip, 'what' => $what, 'misses' => self::REPORT_AFTER, 'minutes' => self::WINDOW_SECONDS / 60])
                    ->log(__('platform.security.lookup_misses', ['ip' => $ip, 'count' => self::REPORT_AFTER, 'minutes' => self::WINDOW_SECONDS / 60])));
            }
        }
    }

    public static function key(?string $ip): string
    {
        return 'public-lookup-miss:'.($ip ?? '-');
    }
}
