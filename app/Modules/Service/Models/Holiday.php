<?php

namespace App\Modules\Service\Models;

use App\Modules\Service\Policies\HolidayPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A day without service for 8x5 / 12x6 SLA clocks (the SLA of 24x7 contracts keeps running).
 */
#[UsePolicy(HolidayPolicy::class)]
class Holiday extends Model
{
    use BelongsToTenant;

    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
