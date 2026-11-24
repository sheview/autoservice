<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SaveBackupSettings;
use App\Modules\Platform\Jobs\RunBackupJob;
use App\Modules\Platform\Support\BackupSettings;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform → สำรองข้อมูล: when the backup runs and how long backups are kept, how the last ones
 * went, "back up now", and downloading one. A backup holds every company's data, so only the
 * superadmin (platform.full_access), from the platform tenant itself.
 */
class BackupController extends Controller
{
    public function index(Request $request, Impersonation $impersonation): Response
    {
        $this->authorizeSuperadmin($request, $impersonation);

        return Inertia::render('Platform/Backups', [
            'settings' => BackupSettings::get(),
            'status' => BackupSettings::status(),
            'backups' => BackupSettings::backups(),
            'stale' => BackupSettings::stale(),
            'encrypted' => BackupSettings::encrypted(),
            'path' => (string) config('filesystems.disks.'.BackupSettings::DISK.'.root'),
            'freeBytes' => BackupSettings::freeBytes(),
            'frequencies' => BackupSettings::FREQUENCIES,
            'limits' => BackupSettings::LIMITS,
        ]);
    }

    public function update(Request $request, Impersonation $impersonation, SaveBackupSettings $save): RedirectResponse
    {
        $this->authorizeSuperadmin($request, $impersonation);

        $limit = fn (string $key) => ['required', 'integer', 'min:'.BackupSettings::LIMITS[$key][0], 'max:'.BackupSettings::LIMITS[$key][1]];
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(BackupSettings::FREQUENCIES)],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'monthday' => ['required', 'integer', 'between:1,28'],
            'keep_days' => $limit('keep_days'),
            'keep_weeks' => $limit('keep_weeks'),
            'keep_months' => $limit('keep_months'),
            'max_storage_mb' => $limit('max_storage_mb'),
            'alert_after_days' => $limit('alert_after_days'),
        ], attributes: __('platform.backups.fields'));

        $save->handle($validated, $request->user());

        return redirect()->route('platform.backups.index')->with('success', __('platform.backups.saved'));
    }

    public function run(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $this->authorizeSuperadmin($request, $impersonation);

        // Outside any company: the backup is of the whole platform.
        RunBackupJob::dispatch();

        activity('platform')->causedBy($request->user())->performedOn($this->platform())
            ->event('backup_started')->log('สั่งสำรองข้อมูลทันที');

        return redirect()->route('platform.backups.index')->with('success', __('platform.backups.started'));
    }

    public function download(Request $request, Impersonation $impersonation, string $file): StreamedResponse
    {
        $this->authorizeSuperadmin($request, $impersonation);

        // Only a backup in the list: never a path someone typed.
        abort_unless(in_array($file, array_column(BackupSettings::backups(), 'file'), true), 404);

        activity('platform')->causedBy($request->user())->performedOn($this->platform())
            ->event('backup_downloaded')->withProperties(['file' => $file])->log('ดาวน์โหลดไฟล์สำรองข้อมูล');

        $path = BackupSettings::destination()->backupName().'/'.$file;

        return Storage::disk(BackupSettings::DISK)->download($path, $file);
    }

    private function authorizeSuperadmin(Request $request, Impersonation $impersonation): void
    {
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(Impersonation::FULL_ACCESS), 403);
    }

    private function platform(): Tenant
    {
        return Tenant::where('is_platform', true)->sole();
    }
}
