<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An appointment a person keeps on their own "my work" calendar. Only its owner (user_id) reads it.
 */
class PersonalEvent extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['user_id', 'title', 'starts_at', 'ends_at', 'all_day', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean', 'user_id' => 'integer'];
    }
}
