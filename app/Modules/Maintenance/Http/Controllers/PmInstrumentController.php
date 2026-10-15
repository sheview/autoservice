<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\SavePmInstrument;
use App\Modules\Maintenance\Actions\SearchPmInstruments;
use App\Modules\Maintenance\Http\Requests\PmInstrumentRequest;
use App\Modules\Maintenance\Models\PmInstrument;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The register of measuring instruments used on PM (ตั้งค่า PM → เครื่องมือวัด). pm-settings.manage,
 * staff only. A deleted instrument stays named on the results that used it.
 */
class PmInstrumentController extends Controller
{
    public function index(Request $request, SearchPmInstruments $search): Response
    {
        $this->ensureAllowed($request);

        $filters = SearchPmInstruments::filtersFrom($request);
        $today = CarbonImmutable::now(config('app.timezone'));

        return Inertia::render('Maintenance/Instruments/Index', [
            'instruments' => $search->handle($filters)->paginate(20)->withQueryString()->through(fn (PmInstrument $instrument) => [
                ...$instrument->only(['id', 'name', 'serial_no', 'notes']),
                'calibration_due_at' => $instrument->calibration_due_at?->toDateString(),
                'calibration' => match (true) {
                    $instrument->calibration_due_at === null => null,
                    $instrument->calibration_due_at->lt($today->startOfDay()) => 'overdue',
                    $instrument->calibration_due_at->lte($today->addDays(30)) => 'soon',
                    default => 'ok',
                },
            ]),
            'filters' => $filters,
        ]);
    }

    public function store(PmInstrumentRequest $request, SavePmInstrument $save): RedirectResponse
    {
        $save->handle(null, $request->validated());

        return back()->with('success', __('maintenance.instruments.saved'));
    }

    public function update(PmInstrumentRequest $request, PmInstrument $instrument, SavePmInstrument $save): RedirectResponse
    {
        $save->handle($instrument, $request->validated());

        return back()->with('success', __('maintenance.instruments.saved'));
    }

    public function destroy(Request $request, PmInstrument $instrument): RedirectResponse
    {
        $this->ensureAllowed($request);

        $instrument->delete();

        return back()->with('success', __('maintenance.instruments.deleted'));
    }

    private function ensureAllowed(Request $request): void
    {
        abort_unless($request->user()->customer_id === null && $request->user()->can('pm-settings.manage'), 403);
    }
}
