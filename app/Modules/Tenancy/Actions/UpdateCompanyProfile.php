<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Platform\Support\Money;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyProfile;
use Illuminate\Http\UploadedFile;

/**
 * Saves the company's profile (CompanyProfile): service phone and e-mail, and a new logo or none.
 */
class UpdateCompanyProfile
{
    /**
     * @param  array{service_phone?: string|null, service_email?: string|null, auto_approve_limit?: string|float|null, reporter_retention_days?: int|null}  $data  auto_approve_limit in baht
     */
    public function handle(Tenant $tenant, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): Tenant
    {
        $settings = $tenant->settings;
        foreach (CompanyProfile::FIELDS as $field) {
            $settings[$field] = filled($data[$field] ?? null) ? trim($data[$field]) : null;
        }
        if (array_key_exists('auto_approve_limit', $data)) {
            $limit = Money::toSatang($data['auto_approve_limit']);
            $settings['checkout'] = [...($settings['checkout'] ?? []), 'auto_approve_limit' => $limit > 0 ? $limit : null];
        }
        if (array_key_exists('reporter_retention_days', $data)) {
            $settings['reporter_retention_days'] = $data['reporter_retention_days'] !== null ? (int) $data['reporter_retention_days'] : null;
        }
        $tenant->settings = $settings;
        $tenant->save();

        if ($logo !== null) {
            $tenant->addMedia($logo)->usingFileName('logo.'.$logo->extension())->toMediaCollection(CompanyProfile::LOGO);
        } elseif ($removeLogo) {
            $tenant->clearMediaCollection(CompanyProfile::LOGO);
        }

        activity()->performedOn($tenant)->event('company_profile_updated')
            ->withProperties(['attributes' => [...array_intersect_key($settings, array_flip(CompanyProfile::FIELDS)), 'logo' => $logo !== null ? 'new' : ($removeLogo ? 'removed' : 'kept')]])
            ->log('แก้ไขข้อมูลบริษัท');

        return $tenant;
    }
}
