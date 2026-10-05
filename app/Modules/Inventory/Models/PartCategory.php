<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of parts (HDD/SSD, power supplies, network cards...). track_serial is what its new
 * parts start with; each part may still be changed on its own.
 */
class PartCategory extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['name', 'track_serial'];

    protected $attributes = ['track_serial' => false];

    protected function casts(): array
    {
        return ['track_serial' => 'boolean'];
    }
}
