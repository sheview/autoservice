<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One province an outside technician works in, or one kind of work they take (kind = province |
 * skill): rows of their own, to be searched on.
 */
class FieldTechnicianTag extends Model
{
    use BelongsToTenant;

    public const PROVINCE = 'province';

    public const SKILL = 'skill';

    protected $fillable = ['field_technician_id', 'kind', 'value'];
}
