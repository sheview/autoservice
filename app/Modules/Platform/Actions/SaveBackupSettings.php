<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\BackupSettings;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Sets when the platform's backup runs and how long backups are kept (BackupSettings), and
 * writes the change to the platform's log with who made it.
 */
class SaveBackupSettings
{
    /**
     * @param  array<string, mixed>  $data  validated, keys of BackupSettings::DEFAULTS
     */
    public function handle(array $data, User $actor): void
    {
        $platform = Tenant::where('is_platform', true)->sole();
        $old = BackupSettings::get();

        $new = array_replace($old, array_intersect_key($data, BackupSettings::DEFAULTS));
        $new['enabled'] = (bool) $new['enabled'];
        foreach (['weekday', 'monthday', 'keep_days', 'keep_weeks', 'keep_months', 'max_storage_mb', 'alert_after_days'] as $number) {
            $new[$number] = (int) $new[$number];
        }

        $settings = $platform->settings;
        $settings[BackupSettings::SETTING] = $new;
        $platform->settings = $settings;
        $platform->save();
        BackupSettings::forget();

        if ($old !== $new) {
            activity('platform')
                ->causedBy($actor)
                ->performedOn($platform)
                ->event('backup_settings_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('ตั้งค่าการสำรองข้อมูล');
        }
    }
}
