<?php

use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Jobs\RunBackupJob;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\BackupSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

/*
 * The platform's backup (every company's data): the superadmin sets when it runs and how long
 * backups are kept, starts one, downloads one; failures and missing backups are alerted on the
 * platform's own channels. The backup itself (mariadb-dump, zip) is the package's: here it is
 * replaced by files on a fake disk.
 */

beforeEach(function () {
    Storage::fake(BackupSettings::DISK);
    $this->superadmin = createSuperadmin();
    $this->platform = $this->superadmin->tenant;

    // the platform is alerted on Telegram
    $this->platform->update(['settings' => [...$this->platform->settings, 'alerts' => [
        'telegram' => ['enabled' => true, 'chat_id' => '-100', 'token' => Crypt::encryptString('123:tg')],
    ]]]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    // A backup's date is its file name (as the package names them).
    $this->backupFile = fn (string $name) => Storage::disk(BackupSettings::DISK)->put('governix/'.$name, 'zip');
    $this->backupAt = fn (string $ago) => ($this->backupFile)(now()->sub($ago)->format('Y-m-d-H-i-s').'.zip');

    $this->settings = [
        'enabled' => true, 'frequency' => 'weekly', 'time' => '01:30', 'weekday' => 7, 'monthday' => 1,
        'keep_days' => 7, 'keep_weeks' => 4, 'keep_months' => 12, 'max_storage_mb' => 20000, 'alert_after_days' => 2,
    ];
});

it('is for the superadmin only: not central staff, not a company admin, not while viewing as a company', function () {
    $this->actingAs($this->superadmin)->get('/platform/backups')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Platform/Backups')->where('settings.enabled', false)->where('encrypted', false));

    $central = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, [], $this->platform);
    $admin = userWithRole('admin_company');
    foreach ([$central, $admin] as $user) {
        $this->actingAs($user)->get('/platform/backups')->assertForbidden();
        $this->actingAs($user)->post('/platform/backups/run')->assertForbidden();
        $this->actingAs($user)->put('/platform/backups', $this->settings)->assertForbidden();
    }

    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($this->superadmin)->get('/platform/backups')->assertForbidden();
});

it('saves when the backup runs and how long backups are kept, and runs it then', function () {
    $this->actingAs($this->superadmin)->put('/platform/backups', $this->settings)->assertSessionHasNoErrors();

    expect(BackupSettings::get())->toMatchArray(['enabled' => true, 'frequency' => 'weekly', 'time' => '01:30', 'weekday' => 7])
        ->and(BackupSettings::cron())->toBe('30 1 * * 0')
        ->and(asTenant($this->platform, fn () => Activity::where('event', 'backup_settings_updated')->exists()))->toBeTrue();

    // the scheduler runs it at that time, Thai time
    $backup = collect(app(Schedule::class)->events())->first(fn ($event) => str_ends_with($event->command, 'platform:backup'));
    expect($backup->expression)->toBe('30 1 * * 0')->and($backup->timezone)->toBe('Asia/Bangkok');

    $this->actingAs($this->superadmin)->put('/platform/backups', [...$this->settings, 'frequency' => 'monthly', 'monthday' => 15]);
    expect(BackupSettings::cron())->toBe('30 1 15 * *');
    $this->actingAs($this->superadmin)->put('/platform/backups', [...$this->settings, 'frequency' => 'daily']);
    expect(BackupSettings::cron())->toBe('30 1 * * *');

    // the kept periods reach the package's clean-up
    BackupSettings::applyToConfig();
    expect(config('backup.cleanup.default_strategy'))->toMatchArray(['keep_all_backups_for_days' => 7, 'keep_weekly_backups_for_weeks' => 4, 'keep_monthly_backups_for_months' => 12]);
});

it('checks the settings', function () {
    $this->actingAs($this->superadmin)->put('/platform/backups', [
        ...$this->settings, 'frequency' => 'hourly', 'time' => '25:00', 'monthday' => 31, 'keep_days' => 0, 'weekday' => 8,
    ])->assertSessionHasErrors(['frequency', 'time', 'monthday', 'keep_days', 'weekday']);

    expect(BackupSettings::get()['enabled'])->toBeFalse();
});

it('starts a backup in the queue, and logs who did', function () {
    Queue::fake();

    $this->actingAs($this->superadmin)->post('/platform/backups/run')->assertRedirect('/platform/backups');

    Queue::assertPushed(RunBackupJob::class);
    expect(asTenant($this->platform, fn () => Activity::where('event', 'backup_started')->where('causer_id', $this->superadmin->id)->exists()))->toBeTrue();
});

it('lists the backups newest first and downloads only one of them', function () {
    ($this->backupFile)('2026-11-20-02-00-00.zip');
    ($this->backupFile)('2026-11-22-02-00-00.zip');
    Storage::disk(BackupSettings::DISK)->put('governix/../secret.zip', 'no');

    $this->actingAs($this->superadmin)->get('/platform/backups')->assertInertia(fn (Assert $page) => $page
        ->where('backups.0.file', '2026-11-22-02-00-00.zip')
        ->where('backups.1.file', '2026-11-20-02-00-00.zip')
        ->has('backups', 2));

    $this->actingAs($this->superadmin)->get('/platform/backups/2026-11-22-02-00-00.zip')
        ->assertOk()->assertDownload('2026-11-22-02-00-00.zip');
    expect(asTenant($this->platform, fn () => Activity::where('event', 'backup_downloaded')->first()->properties['file']))->toBe('2026-11-22-02-00-00.zip');

    $this->actingAs($this->superadmin)->get('/platform/backups/2026-01-01-00-00-00.zip')->assertNotFound();
    $this->actingAs($this->superadmin)->get('/platform/backups/secret.zip')->assertNotFound();
    $this->actingAs($this->superadmin)->get('/platform/backups/..%2Fsecret.zip')->assertNotFound();

    $central = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, [], $this->platform);
    $this->actingAs($central)->get('/platform/backups/2026-11-22-02-00-00.zip')->assertForbidden();
});

it('keeps how the last backup went, and alerts the platform when one fails', function () {
    event(new BackupWasSuccessful(BackupSettings::DISK, 'governix'));
    expect(BackupSettings::status()['last_success_at'])->not->toBeNull();
    Http::assertNothingSent();

    event(new BackupHasFailed(new Exception('mariadb-dump: Got error: 1045 Access denied'), BackupSettings::DISK, 'governix'));

    expect(BackupSettings::status())->toMatchArray(['last_error' => 'mariadb-dump: Got error: 1045 Access denied'])
        ->and(BackupSettings::status()['last_failure_at'])->not->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org/bot123:tg/')
        && str_contains($request['text'], 'สำรองข้อมูลไม่สำเร็จ') && str_contains($request['text'], 'Access denied'));
});

it('alerts the platform when no recent backup exists, every day it checks', function () {
    $this->actingAs($this->superadmin)->put('/platform/backups', $this->settings);

    ($this->backupAt)('5 days');
    $this->artisan('platform:backup --check')->assertSuccessful();
    Http::assertSentCount(1);

    ($this->backupAt)('1 hour');
    $this->artisan('platform:backup --check')->assertSuccessful();
    Http::assertSentCount(1);

    // backups switched off: no check
    $this->actingAs($this->superadmin)->put('/platform/backups', [...$this->settings, 'enabled' => false]);
    Storage::disk(BackupSettings::DISK)->deleteDirectory('governix');
    $this->artisan('platform:backup --check')->assertSuccessful();
    Http::assertSentCount(1);
});
