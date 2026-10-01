<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyProfile;
use Illuminate\Http\UploadedFile;

/**
 * Saves the company's profile (CompanyProfile): service phone and e-mail, and a new logo or none.
 */
class UpdateCompanyProfile
{
    /**
     * @param  array{service_phone?: string|null, service_email?: string|null}  $data
     */
    public function handle(Tenant $tenant, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): Tenant
    {
        $settings = $tenant->settings;
        foreach (CompanyProfile::FIELDS as $field) {
            $settings[$field] = filled($data[$field] ?? null) ? trim($data[$field]) : null;
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
