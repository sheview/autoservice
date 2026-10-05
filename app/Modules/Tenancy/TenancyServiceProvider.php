<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Actions\AssignCompanyCode;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;

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
        $this->keepCompanyCodes();
    }

    /**
     * Every new company gets its company code however it is made (the platform's form, an install
     * command, a test); once given, a code never changes.
     */
    private function keepCompanyCodes(): void
    {
        // Listeners return nothing: a value returned would stop the model's other listeners.
        Tenant::creating(function (Tenant $tenant): void {
            $this->app->make(AssignCompanyCode::class)->handle($tenant);
        });
        Tenant::created(function (Tenant $tenant): void {
            if ($tenant->company_code) {
                DB::table('company_codes')->where('code', $tenant->company_code)->update(['tenant_id' => $tenant->id]);
            }
        });
        Tenant::updating(function (Tenant $tenant) {
            if ($tenant->isDirty('company_code') && $tenant->getOriginal('company_code') !== null) {
                throw new LogicException('A company code never changes.');
            }
        });
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
