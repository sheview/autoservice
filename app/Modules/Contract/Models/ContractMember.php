<?php

namespace App\Modules\Contract\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A member of a project's (contract's) team. Holds only the user id: user data is read through
 * the Identity module's actions (modules do not use each other's models).
 */
class ContractMember extends Model
{
    use BelongsToTenant;

    protected $fillable = ['contract_id', 'user_id'];
}
