<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * How many minutes a signed-in user may stay idle before having to sign in again, for the whole
 * platform. The superadmin sets it (Platform settings page); it is kept in the platform tenant's
 * "settings" and falls back to SESSION_LIFETIME until then.
 */
class SessionTimeout
{
    public const SETTING = 'session_timeout_minutes';

    public const MIN = 5;

    public const MAX = 1440;

    private const CACHE_KEY = 'platform.session_timeout_minutes';

    public static function minutes(): int
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $settings = Tenant::where('is_platform', true)->value('settings') ?? [];

            return (int) ($settings[self::SETTING] ?? config('session.lifetime'));
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
