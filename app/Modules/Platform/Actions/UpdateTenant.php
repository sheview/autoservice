<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Changes a customer company's name, address, status or paid period, and writes the change to
 * the platform's log with who made it.
 */
class UpdateTenant
{
    /**
     * @param  array{name: string, subdomain: string, status: string, subscription_starts_on?: string|null,
     *     subscription_ends_on?: string|null}  $data
     */
    public function handle(Tenant $tenant, array $data, User $actor): Tenant
    {
        $fields = ['name', 'subdomain', 'status', 'subscription_starts_on', 'subscription_ends_on'];
        $old = collect($fields)->mapWithKeys(fn (string $field) => [$field => $this->plain($tenant->{$field})])->all();

        $tenant->fill([
            'name' => $data['name'],
            'subdomain' => $data['subdomain'],
            'status' => $data['status'],
            'subscription_starts_on' => $data['subscription_starts_on'] ?? null,
            'subscription_ends_on' => $data['subscription_ends_on'] ?? null,
        ])->save();

        $new = collect($fields)->mapWithKeys(fn (string $field) => [$field => $this->plain($tenant->{$field})])->all();

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
