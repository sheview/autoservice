<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One decision on a request at one step of its approval, in one round of sending.
 */
class RoomAccessApproval extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ASKED = 'asked';

    protected $fillable = ['request_id', 'step', 'side', 'decision', 'note', 'actor_id', 'actor_name', 'round', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'step' => 'integer', 'round' => 'integer'];
    }
}
