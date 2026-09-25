<?php

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

class TenantCreated
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}
