<?php

namespace App\Modules\Platform\Support;

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
}
