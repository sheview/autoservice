<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\PmPlanPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The PM schedule of one contract. Its period (copied from the contract) is cut into rounds of
 * interval_months; each round is a PmVisit. Contract, customer and users belong to other modules.
 */
#[UsePolicy(PmPlanPolicy::class)]
class PmPlan extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Months between rounds (the same choices as the contract's PM interval). */
    public const INTERVALS = [1, 2, 3, 4, 6, 12];

    protected $fillable = ['contract_id', 'customer_id', 'title', 'interval_months', 'starts_on', 'ends_on', 'assignee_id', 'notes'];

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PmVisit::class);
    }
}
