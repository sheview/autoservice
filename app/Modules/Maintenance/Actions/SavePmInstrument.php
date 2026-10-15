<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmInstrument;

/**
 * Adds or changes a measuring instrument of the company.
 */
class SavePmInstrument
{
    /**
     * @param  array{name: string, serial_no: string, calibration_due_at?: string|null, notes?: string|null}  $data  validated
     */
    public function handle(?PmInstrument $instrument, array $data): PmInstrument
    {
        $instrument ??= new PmInstrument;
        $instrument->fill([
            'name' => $data['name'],
            'serial_no' => trim($data['serial_no']),
            'calibration_due_at' => $data['calibration_due_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $instrument;
    }
}
