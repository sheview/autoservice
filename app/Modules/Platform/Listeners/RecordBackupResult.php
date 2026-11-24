<?php

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\Actions\AlertPlatform;
use App\Modules\Platform\Support\BackupSettings;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;

/**
 * How a backup went (spatie/laravel-backup events): kept for the backup page, and a failure
 * alerted on the platform's channels — a backup that quietly stopped is the one found missing
 * on the day it is needed.
 */
class RecordBackupResult
{
    public function __construct(private AlertPlatform $alert) {}

    public function handle(BackupWasSuccessful|BackupHasFailed|CleanupHasFailed $event): void
    {
        $now = now()->toDateTimeString();

        if ($event instanceof BackupWasSuccessful) {
            BackupSettings::recordStatus(['last_success_at' => $now]);

            return;
        }

        $error = mb_substr($event->exception->getMessage(), 0, 500);
        $kind = $event instanceof BackupHasFailed ? 'failed' : 'cleanup_failed';

        if ($event instanceof BackupHasFailed) {
            BackupSettings::recordStatus(['last_failure_at' => $now, 'last_error' => $error]);
        }

        $this->alert->handle(
            __("platform.backups.alert.{$kind}_title"),
            __("platform.backups.alert.{$kind}_body", ['error' => $error]),
            route('platform.backups.index'),
        );
    }
}
