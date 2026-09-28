<?php

namespace App\Modules\Contract\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An asset covered by a contract. Holds only the asset id: asset data is read through
 * the Asset module's actions (modules do not use each other's models).
 */
class ContractAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = ['contract_id', 'asset_id'];
}
