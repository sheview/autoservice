<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\AlertPlatform;
use App\Modules\Platform\Actions\RunBackup;
use App\Modules\Platform\Support\BackupSettings;
use Illuminate\Console\Command;

/**
 * The platform's backup, run by the scheduler at the time set on the backup page:
 *   platform:backup          one backup now, then clean-up of the old ones
 *   platform:backup --check  alert when the newest backup is older than set (daily)
 */
class BackupCommand extends Command
{
    protected $signature = 'platform:backup {--check : Only check that a recent backup exists}';

    protected $description = 'Back up the platform (database and uploaded files), or check the backups are recent';

    public function handle(RunBackup $runBackup, AlertPlatform $alert): int
    {
        if ($this->option('check')) {
            $settings = BackupSettings::get();
            if ($settings['enabled'] && BackupSettings::stale()) {
                $alert->handle(
                    __('platform.backups.alert.stale_title'),
                    __('platform.backups.alert.stale_body', ['days' => $settings['alert_after_days']]),
                    route('platform.backups.index'),
                );
                $this->warn('No recent backup.');
            }

            return self::SUCCESS;
        }

        return $runBackup->handle() ? self::SUCCESS : self::FAILURE;
    }
}
