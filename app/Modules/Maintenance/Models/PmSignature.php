<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signature on a work order. image_path is on the private disk; data_hash is the SHA-256 of the
 * results as they were when signed.
 */
class PmSignature extends Model
{
    use BelongsToTenant;

    public const ROLE_ENGINEER = 'engineer';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_APPROVER = 'approver';

    public const ROLES = [self::ROLE_ENGINEER, self::ROLE_CUSTOMER, self::ROLE_APPROVER];

    protected $fillable = ['role', 'name', 'position', 'phone', 'email', 'image_path', 'signed_at', 'ip', 'user_agent', 'data_hash'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }
}
