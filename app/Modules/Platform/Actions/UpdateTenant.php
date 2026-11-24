<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Jobs\SyncKeycloakAccountsJob;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Bus;

/**
 * Changes a customer company's name, address, status or paid period, and writes the change to
 * the platform's log with who made it.
 */
class UpdateTenant
{
    /**
     * @param  array{name: string, subdomain: string, status: string, subscription_starts_on?: string|null,
     *     subscription_ends_on?: string|null, auth_mode?: string, sso_source?: string, keycloak_org?: string|null}  $data
     */
    public function handle(Tenant $tenant, array $data, User $actor): Tenant
    {
        $fields = ['name', 'subdomain', 'status', 'subscription_starts_on', 'subscription_ends_on', 'auth_mode', 'sso_source', 'keycloak_org'];
        $ssoSource = $data['sso_source'] ?? $tenant->sso_source;
        $old = collect($fields)->mapWithKeys(fn (string $field) => [$field => $this->plain($tenant->{$field})])->all();

        $tenant->fill([
            'name' => $data['name'],
            'subdomain' => $data['subdomain'],
            'status' => $data['status'],
            'subscription_starts_on' => $data['subscription_starts_on'] ?? null,
            'subscription_ends_on' => $data['subscription_ends_on'] ?? null,
            'auth_mode' => $data['auth_mode'] ?? $tenant->auth_mode,
            'sso_source' => $ssoSource,
            // An Organization only for a company on its own system: any other sign-in must come with none.
            'keycloak_org' => $ssoSource === Tenant::SSO_IDP ? ($data['keycloak_org'] ?? $tenant->keycloak_org) : null,
        ])->save();

        $new = collect($fields)->mapWithKeys(fn (string $field) => [$field => $this->plain($tenant->{$field})])->all();

        // SSO just turned on: every staff member gets their Keycloak account (and an e-mail to set a password).
        if ($old['auth_mode'] === Tenant::AUTH_PASSWORD && $new['auth_mode'] !== Tenant::AUTH_PASSWORD) {
            // Bus::dispatch, not ::dispatch(): a PendingDispatch would queue the job only when dropped,
            // after run() has left the company, and the job would carry the wrong tenant.
            app(TenantContext::class)->run($tenant, fn () => Bus::dispatch(new SyncKeycloakAccountsJob));
        }

        if ($old !== $new) {
            // In the platform tenant's log (the actor's own tenant).
            activity('platform')
                ->causedBy($actor)
                ->performedOn($tenant)
                ->event('tenant_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('แก้ไขข้อมูลบริษัท');
        }

        return $tenant;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
