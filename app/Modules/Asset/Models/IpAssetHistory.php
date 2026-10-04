<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened to an address (reserved, given to an asset, released, ...), with the
 * device as it was then. Never changed or removed.
 */
class IpAssetHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ACTIONS = ['reserved', 'assigned', 'released', 'excluded', 'included', 'updated'];

    protected $fillable = ['ip_address_id', 'action', 'asset_id', 'asset_code', 'hostname', 'mac_address', 'user_id', 'notes'];

    protected function casts(): array
    {
        return ['asset_id' => 'integer', 'user_id' => 'integer'];
    }
}
