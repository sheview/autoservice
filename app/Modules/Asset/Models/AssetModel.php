<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A model of a brand in the asset catalog. Names are unique per brand, without case.
 */
class AssetModel extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['asset_brand_id', 'asset_category_id', 'name'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(AssetBrand::class, 'asset_brand_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id')->withTrashed();
    }
}
