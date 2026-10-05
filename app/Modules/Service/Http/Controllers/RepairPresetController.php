<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Actions\RepairPresetList;
use App\Modules\Service\Actions\SaveRepairPresets;
use App\Modules\Service\Models\RepairPreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's symptom and fix chips (quick close, the customer's report form), kept by its
 * admin (company.manage) as two simple lists.
 */
class RepairPresetController extends Controller
{
    public const PERMISSION = 'company.manage';

    public function edit(RepairPresetList $list): Response
    {
        Gate::authorize(self::PERMISSION);

        return Inertia::render('Service/Presets', ['presets' => $list->handle(), 'kinds' => RepairPreset::KINDS]);
    }

    public function update(Request $request, SaveRepairPresets $save): RedirectResponse
    {
        Gate::authorize(self::PERMISSION);
        $data = $request->validate([
            'symptom' => ['array', 'max:60'],
            'symptom.*' => ['required', 'string', 'max:100', 'distinct'],
            'solution' => ['array', 'max:60'],
            'solution.*' => ['required', 'string', 'max:100', 'distinct'],
        ], attributes: __('service.presets.fields'));

        $save->handle(['symptom' => $data['symptom'] ?? [], 'solution' => $data['solution'] ?? []]);

        return back()->with('success', __('service.presets.saved'));
    }
}
