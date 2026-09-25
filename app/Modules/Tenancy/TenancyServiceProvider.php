<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /** @var list<int|null> tenant ids to restore after each job */
    private array $previous = [];

    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->carryTenantThroughQueue();
    }

    private function carryTenantThroughQueue(): void
    {
        // Dispatch: store the current tenant in the payload of InteractsWithTenant jobs.
        Queue::createPayloadUsing(function ($connection, $queue, array $payload) {
            $command = $payload['data']['commandName'] ?? null;

            if ($command === null || ! in_array(InteractsWithTenant::class, class_uses_recursive($command), true)) {
                return [];
            }

            return ['tenant_id' => $this->app->make(TenantContext::class)->id()];
        });

        // Worker: switch to the job's tenant before handle(), switch back afterwards.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $payload = $event->job->payload();
            if (! array_key_exists('tenant_id', $payload)) {
                return;
            }

            $context = $this->app->make(TenantContext::class);
            $this->previous[] = $context->id();
            $context->set($payload['tenant_id']);
        });

        $restore = function (JobProcessed|JobExceptionOccurred $event) {
            if (! array_key_exists('tenant_id', $event->job->payload())) {
                return;
            }

            $this->app->make(TenantContext::class)->set(array_pop($this->previous));
        };

        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }
}
