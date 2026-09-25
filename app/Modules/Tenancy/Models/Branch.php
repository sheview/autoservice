<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Policies\BranchPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(BranchPolicy::class)]
class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['code', 'name', 'address', 'province'];
}
