<?php

namespace App\Modules\Contract\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Response and resolve time of one ticket priority in a contract.
 */
class ContractSla extends Model
{
    use BelongsToTenant;

    protected $fillable = ['priority', 'response_minutes', 'resolve_minutes'];

    protected function casts(): array
    {
        return [
            'response_minutes' => 'integer',
            'resolve_minutes' => 'integer',
        ];
    }
}
