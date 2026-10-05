<?php

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile, the light CAPTCHA of the public forms (no package: one HTTP call).
 * Without keys (development) it is off and every check passes; with keys, a missing or wrong
 * token fails, and so does Cloudflare not answering (the form can be sent again).
 */
class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key')) && filled(config('services.turnstile.site_key'));
    }

    /** The key the page needs to show the widget, or null when the CAPTCHA is off. */
    public static function siteKey(): ?string
    {
        return self::enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public static function passes(?string $token, ?string $ip): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if (blank($token)) {
            return false;
        }

        try {
            return (bool) Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ])->json('success', false);
        } catch (Throwable) {
            return false;
        }
    }
}
