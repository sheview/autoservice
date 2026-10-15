<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Ties a template (by code: its newest published version) to the assets it checks. The filled
 * columns say what it matches; none filled = the default template. See PmTemplateResolver.
 */
class PmTemplateAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['template_code', 'asset_category_id', 'asset_brand_id', 'asset_model_id', 'asset_id'];
}
