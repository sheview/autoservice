<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A brand in the asset catalog (category → brand → model). Names are unique per tenant, without case.
 */
class AssetBrand extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['name'];

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }
}
