<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmInstrument;
use Carbon\CarbonImmutable;

/**
 * The company's measuring instruments to pick from when recording PM, with whether their
 * calibration is past due.
 */
class PmInstrumentOptions
{
    /**
     * @return list<array{id: int, name: string, serial_no: string, calibration_due_at: string|null, calibration_overdue: bool}>
     */
    public function handle(): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return PmInstrument::query()->orderBy('name')->orderBy('serial_no')->get()
            ->map(fn (PmInstrument $instrument) => [
                'id' => $instrument->id,
                'name' => $instrument->name,
                'serial_no' => $instrument->serial_no,
                'calibration_due_at' => $instrument->calibration_due_at?->toDateString(),
                'calibration_overdue' => $instrument->calibration_due_at !== null && $instrument->calibration_due_at->toDateString() < $today,
            ])->all();
    }
}
