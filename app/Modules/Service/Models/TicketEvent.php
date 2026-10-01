<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a ticket's timeline: created, assigned, status change, comment or edit.
 */
class TicketEvent extends Model
{
    use BelongsToTenant;

    public const TYPE_CREATED = 'created';

    public const TYPE_ASSIGNED = 'assigned';

    public const TYPE_STATUS = 'status';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_UPDATED = 'updated';

    /** The device's warranty was checked (body: what was found). */
    public const TYPE_WARRANTY = 'warranty';

    protected $fillable = ['user_id', 'user_name', 'type', 'from_status', 'to_status', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }
}
