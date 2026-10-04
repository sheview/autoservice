<?php

namespace App\Modules\Contract\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place of a customer (head office, a branch, a data center). Managed on the customer's page.
 */
class CustomerSite extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['customer_id', 'name', 'address', 'notes'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
