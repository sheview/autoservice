<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One serial number of an asset. Unique per tenant (ignoring case) among serials not removed.
 */
class AssetSerial extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['asset_id', 'serial_number'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }
}
