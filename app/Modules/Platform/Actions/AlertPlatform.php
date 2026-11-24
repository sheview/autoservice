<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Support\AlertSender;
use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Tells the platform's own people about something of the whole platform (a backup failed) on
 * every channel set in the platform tenant's alert settings (ตั้งค่าการแจ้งเตือน, signed in as
 * the platform). Sent right away: it is called from the scheduler or a queued job already.
 * A channel that fails is reported and does not stop the others.
 */
class AlertPlatform
{
    public function __construct(private AlertSender $sender) {}

    public function handle(string $title, string $body, ?string $url = null): void
    {
        $platform = Tenant::where('is_platform', true)->first();
        if ($platform === null) {
            return;
        }

        foreach (AlertSettings::channels($platform) as $channel => $config) {
            try {
                $this->sender->send($channel, $config, $title, $body, $url);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
