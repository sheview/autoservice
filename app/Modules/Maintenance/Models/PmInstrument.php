<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A measuring instrument used on PM. Serial numbers are unique per tenant.
 */
class PmInstrument extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['name', 'serial_no', 'calibration_due_at', 'notes'];

    protected function casts(): array
    {
        return [
            'calibration_due_at' => 'date',
        ];
    }
}
