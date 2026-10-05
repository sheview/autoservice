<?php

namespace App\Modules\Service\Console;

use App\Modules\Service\Actions\AnonymizeReporters;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: in every company, blank out QR reporters' details kept past the company's period.
 */
class AnonymizeReportersCommand extends Command
{
    protected $signature = 'tickets:anonymize-reporters';

    protected $description = 'Blank out QR reporters (name, phone, e-mail) past each company\'s retention period';

    public function handle(TenantContext $context, AnonymizeReporters $anonymize): int
    {
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, $anonymize) {
            $count = $context->run($tenant, fn () => $anonymize->handle($tenant));
            if ($count > 0) {
                $this->info("{$tenant->name}: {$count}");
            }
        });

        return self::SUCCESS;
    }
}
