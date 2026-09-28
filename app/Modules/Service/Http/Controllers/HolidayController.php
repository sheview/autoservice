<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Actions\DeleteHoliday;
use App\Modules\Service\Actions\SaveHoliday;
use App\Modules\Service\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Days off of the company (per year), used by the SLA clock of 8x5 / 12x6 contracts.
 */
class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Holiday::class);

        $year = $request->integer('year') ?: now()->year;

        return Inertia::render('Service/Holidays/Index', [
            'year' => $year,
            'holidays' => Holiday::query()
                ->whereYear('date', $year)
                ->orderBy('date')
                ->get()
                ->map(fn (Holiday $holiday) => ['id' => $holiday->id, 'date' => $holiday->date->toDateString(), 'name' => $holiday->name]),
            'can' => [
                'create' => $request->user()->can('create', Holiday::class),
                'delete' => $request->user()->can('holiday.delete'),
            ],
        ]);
    }

    public function store(Request $request, SaveHoliday $saveHoliday): RedirectResponse
    {
        Gate::authorize('create', Holiday::class);

        $validated = $request->validate([
            // One entry per day (the tenant scope limits "unique" to this tenant).
            'date' => ['required', 'date', Rule::unique('holidays', 'date')],
            'name' => ['required', 'string', 'max:255'],
        ], attributes: __('service.holiday_fields'));

        $holiday = $saveHoliday->handle($validated);

        return redirect()->route('service.holidays.index', ['year' => $holiday->date->year])->with('success', __('service.holidays.created'));
    }

    public function destroy(Holiday $holiday, DeleteHoliday $deleteHoliday): RedirectResponse
    {
        Gate::authorize('delete', $holiday);

        $deleteHoliday->handle($holiday);

        return back()->with('success', __('service.holidays.deleted'));
    }
}
