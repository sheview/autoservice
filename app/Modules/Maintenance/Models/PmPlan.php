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
 * interval_months (1 monthly, 3 quarterly, 6 half-yearly, 12 yearly); each round is a PmVisit. Contract, customer and users belong to other modules.
 */
#[UsePolicy(PmPlanPolicy::class)]
class PmPlan extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Months between rounds (the same choices as the contract's PM interval). */
    public const INTERVALS = [1, 2, 3, 4, 6, 12];

    protected $fillable = ['contract_id', 'customer_id', 'title', 'interval_months', 'starts_on', 'ends_on', 'assignee_id', 'sign_per_sheet', 'active', 'notes'];

    protected $attributes = [
        'sign_per_sheet' => false,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'sign_per_sheet' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PmVisit::class);
    }

    /** The equipment the plan covers; none = every asset of the contract. */
    public function assets(): HasMany
    {
        return $this->hasMany(PmPlanAsset::class);
    }
}
