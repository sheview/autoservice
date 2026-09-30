<?php

namespace App\Modules\Labeling\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One asset label printed by a user (the print log). Asset and user belong to other modules.
 */
class AssetLabelPrint extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['asset_id', 'user_id', 'template', 'printed_at'];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
        ];
    }
}
