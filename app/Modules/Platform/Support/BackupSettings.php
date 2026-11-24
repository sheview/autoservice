<?php

namespace App\Modules\Platform\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;

/**
 * The platform's backup (every company's data and files, spatie/laravel-backup): when it runs,
 * how long backups are kept, and how the last one went. Set by the superadmin (Platform →
 * สำรองข้อมูล); kept in the platform tenant's "settings" ("backup", "backup_status").
 *
 * The zip password is not here but in .env (BACKUP_ARCHIVE_PASSWORD): a password kept in the
 * database would be lost with the database it is meant to bring back.
 */
class BackupSettings
{
    public const SETTING = 'backup';

    public const STATUS = 'backup_status';

    public const DISK = 'backups';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const DEFAULTS = [
        'enabled' => false,
        'frequency' => 'daily',
        'time' => '02:00',
        'weekday' => 7,     // ISO: 1 = Monday … 7 = Sunday (weekly)
        'monthday' => 1,    // 1–28, a day every month has (monthly)
        'keep_days' => 7,   // every backup of the last N days
        'keep_weeks' => 4,  // then one a week
        'keep_months' => 12, // then one a month
        'max_storage_mb' => 20000, // the oldest go first above this
        'alert_after_days' => 2, // no new backup for this long: alert
    ];

    /** Limits of the form. */
    public const LIMITS = [
        'keep_days' => [1, 90], 'keep_weeks' => [0, 52], 'keep_months' => [0, 120],
        'max_storage_mb' => [100, 10_000_000], 'alert_after_days' => [1, 60],
    ];

    private const CACHE_KEY = 'platform.backup_settings';

    /**
     * @return array{enabled: bool, frequency: string, time: string, weekday: int, monthday: int, keep_days: int,
     *     keep_weeks: int, keep_months: int, max_storage_mb: int, alert_after_days: int}
     */
    public static function get(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => self::platform()?->settings[self::SETTING] ?? []);

        return array_replace(self::DEFAULTS, array_intersect_key((array) $saved, self::DEFAULTS));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * When the scheduler runs it (Asia/Bangkok), as a cron expression.
     */
    public static function cron(): string
    {
        $settings = self::get();
        [$hour, $minute] = array_map('intval', explode(':', $settings['time']));

        return match ($settings['frequency']) {
            'weekly' => "{$minute} {$hour} * * ".($settings['weekday'] % 7), // cron: 0 = Sunday
            'monthly' => "{$minute} {$hour} {$settings['monthday']} * *",
            default => "{$minute} {$hour} * * *",
        };
    }

    /**
     * The package's clean-up (backup:clean) with the kept periods set here.
     */
    public static function applyToConfig(): void
    {
        $settings = self::get();

        config([
            'backup.cleanup.default_strategy' => [
                'keep_all_backups_for_days' => $settings['keep_days'],
                'keep_daily_backups_for_days' => 0,
                'keep_weekly_backups_for_weeks' => $settings['keep_weeks'],
                'keep_monthly_backups_for_months' => $settings['keep_months'],
                'keep_yearly_backups_for_years' => 0,
                'delete_oldest_backups_when_using_more_megabytes_than' => $settings['max_storage_mb'],
            ],
        ]);
    }

    /**
     * How the last backups went.
     *
     * @return array{last_success_at: string|null, last_failure_at: string|null, last_error: string|null}
     */
    public static function status(): array
    {
        $status = self::platform()?->settings[self::STATUS] ?? [];

        return [
            'last_success_at' => $status['last_success_at'] ?? null,
            'last_failure_at' => $status['last_failure_at'] ?? null,
            'last_error' => $status['last_error'] ?? null,
        ];
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    public static function recordStatus(array $changes): void
    {
        $platform = self::platform();
        if ($platform === null) {
            return;
        }
        $settings = $platform->settings;
        $settings[self::STATUS] = [...($settings[self::STATUS] ?? []), ...$changes];
        $platform->settings = $settings;
        $platform->save();
    }

    public static function destination(): BackupDestination
    {
        return BackupDestination::create(self::DISK, (string) config('backup.backup.name'));
    }

    /**
     * The backups on the disk, newest first.
     *
     * @return list<array{file: string, date: string, size: int}>
     */
    public static function backups(): array
    {
        try {
            $backups = self::destination()->backups();
        } catch (Throwable) {
            return [];
        }

        return $backups->map(fn (Backup $backup) => [
            'file' => basename($backup->path()),
            'date' => $backup->date()->timezone(config('app.timezone'))->toDateTimeString(),
            'size' => (int) $backup->sizeInBytes(),
        ])->values()->all();
    }

    /** The newest backup is older than alert_after_days (or there is none). */
    public static function stale(): bool
    {
        $days = self::get()['alert_after_days'];

        // Not the package's newestBackupIsOlderThan(): deprecated, and it answers the opposite.
        try {
            $newest = self::destination()->newestBackup();
        } catch (Throwable) {
            return true;
        }

        return $newest === null || $newest->date()->lt(Carbon::now()->subDays($days));
    }

    /** Free space where the backups go, in bytes (null when it cannot be read). */
    public static function freeBytes(): ?int
    {
        $root = (string) config('filesystems.disks.'.self::DISK.'.root');
        $free = is_dir($root) ? @disk_free_space($root) : false;

        return $free === false ? null : (int) $free;
    }

    public static function encrypted(): bool
    {
        return filled(config('backup.backup.password'));
    }

    private static function platform(): ?Tenant
    {
        return Tenant::where('is_platform', true)->first();
    }
}
