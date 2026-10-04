<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A network of a customer at one of its sites (a LAN, a VLAN), or of the company itself
 * (customer_id null). customer_id / site_id point into the Contract module: names come
 * through its actions, not through relations.
 */
class Network extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['customer_id', 'site_id', 'name', 'vlan_id', 'description'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'site_id' => 'integer', 'vlan_id' => 'integer'];
    }

    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class)->orderBy('first_int');
    }
}
