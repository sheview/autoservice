<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\PmChecklistPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What to check on an asset during PM. asset_category_id null = the checklist for assets whose
 * category has none of its own.
 *
 * @property list<array{key: string, label: string, type: string}> $items
 */
#[UsePolicy(PmChecklistPolicy::class)]
class PmChecklist extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** check = done / not done, text = free text, number = a reading (e.g. temperature). */
    public const ITEM_TYPES = ['check', 'text', 'number'];

    protected $fillable = ['name', 'asset_category_id', 'items'];

    protected $attributes = [
        'items' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
        ];
    }
}
