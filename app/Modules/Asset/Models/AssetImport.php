<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One uploaded Excel file of assets and the result of importing it (ImportAssetsJob).
 */
class AssetImport extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'file_name', 'file_path', 'status',
        'total_rows', 'created_rows', 'updated_rows', 'failed_rows', 'errors', 'finished_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'errors' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
