<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved PM report, frozen when approved: "snapshot" is everything both PDFs show
 * (PmReportData), so a version prints the same whatever happens to the work order later.
 * Append only; a correction is a new version.
 *
 * @property array<string, mixed> $snapshot
 */
class PmReportVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['pm_visit_id', 'version', 'snapshot', 'approved_by', 'approved_by_name', 'created_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }
}
