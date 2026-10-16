<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\CrossTenant\CompanyStaff;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Several "this person works in that company" switches at once (page "ช่างหลายบริษัท": ticked,
 * then saved together). Each goes through SetStaffCompany on its own, so one refused (the
 * person's own company, a company's last admin) does not hold back the others.
 */
class SetStaffCompanies
{
    public function __construct(private CompanyStaff $staff, private SetStaffCompany $setCompany) {}

    /**
     * @param  list<array{user: int, tenant_id: int, active: bool}>  $changes  validated
     * @return array{saved: int, failed: list<string>} the refused ones as "name — company: reason"
     */
    public function handle(array $changes): array
    {
        $tenants = Tenant::whereIn('id', array_column($changes, 'tenant_id'))->get()->keyBy('id');
        $saved = 0;
        $failed = [];

        foreach ($changes as $change) {
            $main = $this->staff->main((int) $change['user']);
            $tenant = $tenants[$change['tenant_id']] ?? null;
            if ($main === null || $tenant === null) {
                continue;
            }

            try {
                $this->setCompany->handle($main, $tenant, (bool) $change['active']);
                $saved++;
            } catch (ValidationException $e) {
                $failed[] = "{$main->name} — {$tenant->name}: ".collect($e->errors())->flatten()->first();
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }
}
