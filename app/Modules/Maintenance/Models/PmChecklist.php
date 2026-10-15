<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\PmChecklistPolicy;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A PM template: what to check on an asset. One row per (code, version); a published version is
 * not edited again, a change is the next version. Which assets it checks is in
 * pm_template_assignments (PmTemplateResolver); asset_category_id is the category the checklist
 * screen still ties it to.
 *
 * @property list<array<string, mixed>> $items questions, see ChecklistItems
 */
#[UsePolicy(PmChecklistPolicy::class)]
class PmChecklist extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** The question types (ChecklistItems::TYPES); kept for older callers. */
    public const ITEM_TYPES = ChecklistItems::TYPES;

    /** The MA service lines (as asset categories have them). */
    public const SERVICE_LINES = ['datacenter', 'network', 'pc'];

    public const LAYOUT_PER_ASSET = 'per_asset';

    public const LAYOUT_GROUPED_TABLE = 'grouped_table';

    /** per_asset: a page per piece of equipment (data center); grouped_table: a row per piece (PC, network). */
    public const LAYOUTS = [self::LAYOUT_PER_ASSET, self::LAYOUT_GROUPED_TABLE];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /** Shared blocks a template may show on the sheet. */
    public const BLOCKS = ['customer_info', 'case_details', 'parts_usage', 'response_time', 'acknowledgement', 'satisfaction'];

    protected $fillable = ['code', 'name', 'service_line', 'layout', 'version', 'status', 'published_at', 'asset_category_id', 'items', 'blocks'];

    protected $attributes = [
        'items' => '[]',
        'layout' => self::LAYOUT_PER_ASSET,
        'version' => 1,
        'status' => self::STATUS_PUBLISHED,
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'blocks' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
