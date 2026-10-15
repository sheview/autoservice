<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\SavePmSettings;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's PM settings: the review step, the signatures asked for on hand-in, how long a
 * customer link lasts and the note printed on every report. pm-settings.manage, staff only.
 */
class PmSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->ensureAllowed($request);

        return Inertia::render('Maintenance/Settings', [
            'settings' => PmSettings::of()->toArray(),
            'maxShareDays' => PmReportShare::MAX_DAYS,
            'noteMax' => PmSettings::NOTE_MAX,
        ]);
    }

    public function update(Request $request, TenantContext $context, SavePmSettings $save): RedirectResponse
    {
        $this->ensureAllowed($request);

        $data = $request->validate([
            'review_step' => ['required', 'boolean'],
            'require_technician_signature' => ['required', 'boolean'],
            'require_customer_signature' => ['required', 'boolean'],
            'share_days' => ['required', 'integer', 'min:1', 'max:'.PmReportShare::MAX_DAYS],
            'report_note' => ['nullable', 'string', 'max:'.PmSettings::NOTE_MAX],
        ], [], __('maintenance.fields'));

        $save->handle($context->tenant(), $data, $request->user());

        return back()->with('success', __('maintenance.settings.saved'));
    }

    private function ensureAllowed(Request $request): void
    {
        Gate::authorize('pm-settings.manage');
        abort_if($request->user()->customer_id !== null, 403);
    }
}
