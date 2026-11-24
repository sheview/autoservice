<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Support\BackupSettings;
use Illuminate\Support\Facades\Artisan;

/**
 * One backup of the platform (database + uploaded files, spatie/laravel-backup), then removes
 * the backups older than the kept periods. How it went is recorded and alerted by
 * Listeners\RecordBackupResult. True when the backup was made.
 */
class RunBackup
{
    public function handle(): bool
    {
        BackupSettings::applyToConfig();

        $made = Artisan::call('backup:run', ['--disable-notifications' => true]) === 0;

        // Only after a good backup: never thin out the old ones when no new one was made.
        if ($made) {
            Artisan::call('backup:clean', ['--disable-notifications' => true]);
        }

        return $made;
    }
}
