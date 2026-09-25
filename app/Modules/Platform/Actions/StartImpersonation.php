<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

class StartImpersonation
{
    public function __construct(
        private Impersonation $impersonation,
        private TenantContext $context,
    ) {}

    public function handle(User $superadmin, Tenant $tenant, Session $session): void
    {
        if ($tenant->is_platform || ! $this->impersonation->mayImpersonate($superadmin)) {
            throw new AuthorizationException;
        }

        $this->impersonation->start($session, $tenant);

        // Written into the customer's own log, so the customer can see who entered.
        $this->context->run($tenant, function () use ($superadmin, $tenant) {
            activity('impersonation')
                ->causedBy($superadmin)
                ->performedOn($tenant)
                ->event('started')
                ->log('เริ่มเข้าดูในนามบริษัท');
        });
    }
}
