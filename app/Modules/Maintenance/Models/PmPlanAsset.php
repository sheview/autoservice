<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An asset a PM plan covers; template_code overrides the template the asset would otherwise get.
 */
class PmPlanAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = ['pm_plan_id', 'asset_id', 'template_code'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PmPlan::class, 'pm_plan_id');
    }
}
