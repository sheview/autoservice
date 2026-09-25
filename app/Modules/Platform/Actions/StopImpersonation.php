<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Session\Session;

class StopImpersonation
{
    public function __construct(
        private Impersonation $impersonation,
        private TenantContext $context,
    ) {}

    public function handle(User $superadmin, Session $session): void
    {
        $tenant = $this->impersonation->tenant();

        if ($tenant !== null) {
            $this->context->run($tenant, function () use ($superadmin, $tenant) {
                activity('impersonation')
                    ->causedBy($superadmin)
                    ->performedOn($tenant)
                    ->event('stopped')
                    ->log('ออกจากโหมดเข้าดูในนามบริษัท');
            });
        }

        $this->impersonation->stop($session);
    }
}
