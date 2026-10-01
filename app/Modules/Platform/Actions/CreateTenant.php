<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * A new customer company: the tenant (its default roles are seeded by TenantCreated) and its
 * first admin account, who then sets up branches, users and the rest.
 */
class CreateTenant
{
    public function __construct(
        private TenantContext $context,
        private SaveUser $saveUser,
    ) {}

    /**
     * @param  array{name: string, subdomain: string, status: string, subscription_starts_on?: string|null,
     *     subscription_ends_on?: string|null, admin_name: string, admin_email: string, admin_password: string}  $data
     */
    public function handle(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['subdomain'],
                'subdomain' => $data['subdomain'],
                'status' => $data['status'],
                'subscription_starts_on' => $data['subscription_starts_on'] ?? null,
                'subscription_ends_on' => $data['subscription_ends_on'] ?? null,
            ]);

            $this->context->run($tenant, fn () => $this->saveUser->handle(null, [
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
                'role' => 'admin_company',
            ]));

            return $tenant;
        });
    }
}
