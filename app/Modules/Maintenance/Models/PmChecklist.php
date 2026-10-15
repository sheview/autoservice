<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\PmChecklistPolicy;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What to check on an asset during PM. asset_category_id null = the checklist for assets whose
 * category has none of its own.
 *
 * @property list<array<string, mixed>> $items questions, see ChecklistItems
 */
#[UsePolicy(PmChecklistPolicy::class)]
class PmChecklist extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** The question types (ChecklistItems::TYPES); kept for older callers. */
    public const ITEM_TYPES = ChecklistItems::TYPES;

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
