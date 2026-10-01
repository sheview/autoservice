<?php

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Support\AlertSender;
use App\Modules\Platform\Support\AlertSettings;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Sends an alert through every channel the company set for the event. A channel that fails is
 * reported and does not stop the others (nor is the alert sent twice to those that worked).
 */
class DeliverAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function __construct(
        public string $event,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function handle(TenantContext $context, AlertSender $sender): void
    {
        $tenant = $context->tenant();
        if ($tenant === null) {
            return;
        }

        foreach (AlertSettings::channels($tenant, $this->event) as $channel => $config) {
            try {
                $sender->send($channel, $config, $this->title, $this->body, $this->url);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
