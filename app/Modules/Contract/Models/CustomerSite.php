<?php

namespace App\Modules\Contract\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place of a customer (head office, a branch, a data center). Managed on the customer's page.
 * lat / lng place it on the map (both or neither).
 */
class CustomerSite extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['customer_id', 'name', 'address', 'province', 'lat', 'lng', 'notes'];

    protected function casts(): array
    {
        return ['lat' => 'decimal:7', 'lng' => 'decimal:7'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
