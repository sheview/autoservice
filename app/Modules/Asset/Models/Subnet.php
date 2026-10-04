<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An IPv4 subnet of a network (cidr "192.168.1.0/24"; first_int = the network address).
 * Its usable addresses are worked out from the prefix (IpRange); only addresses with
 * something on them have an IpAddress row.
 */
class Subnet extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['network_id', 'cidr', 'first_int', 'prefix', 'gateway', 'description'];

    protected function casts(): array
    {
        return ['first_int' => 'integer', 'prefix' => 'integer'];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class)->withTrashed();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
