<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A symptom or a fix offered as a chip (quick close on a phone, the customer's report form).
 */
class RepairPreset extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const KINDS = ['symptom', 'solution'];

    protected $fillable = ['kind', 'label', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }
}
