<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Tells the company about something that happened (AlertSettings::EVENTS) on LINE, Telegram and
 * e-mail, as it set them. Queued after the transaction commits; nothing when the company does
 * not want the event or has no channel ready. Texts: lang/th/alerts.php "events.{event}".
 */
class SendAlert
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array<string, string|int|null>  $replace  placeholders of the event's texts
     */
    public function handle(string $event, array $replace = [], ?string $url = null): void
    {
        $tenant = $this->context->tenant();
        if ($tenant === null || $tenant->is_platform || AlertSettings::channels($tenant, $event) === []) {
            return;
        }

        $replace = array_map(fn ($value) => (string) ($value ?? '-'), $replace);
        $title = __("alerts.events.{$event}.title", $replace).' · '.$tenant->name;
        $body = __("alerts.events.{$event}.body", $replace);

        DeliverAlert::dispatch($event, $title, $body, $url)->afterCommit();
    }
}
