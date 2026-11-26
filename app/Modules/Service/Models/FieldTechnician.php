<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An outside technician who signed up through the company's link: how to reach them, the
 * provinces they work in and the kinds of work they take. Found when looking for someone to send
 * on a job only once staff approved them.
 */
class FieldTechnician extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** The kinds of work a technician may say they take (labels in lang ui.field_technicians.skills). */
    public const SKILLS = ['pc', 'notebook', 'printer', 'network', 'cctv', 'server', 'ups', 'pos', 'telephone', 'cabling'];

    /** Provinces one technician may cover, at most. */
    public const MAX_PROVINCES = 20;

    protected $fillable = [
        'name', 'company', 'phone', 'phone_key', 'line_id', 'email', 'note', 'status', 'reviewed_at', 'reviewed_by_name', 'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function tags(): HasMany
    {
        return $this->hasMany(FieldTechnicianTag::class);
    }

    /** The digits of a phone number, the key that tells the same person apart. */
    public static function phoneKey(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone) ?? '', 0, 20);
    }

    /** @return list<string> */
    public function tagValues(string $kind): array
    {
        return $this->tags->where('kind', $kind)->pluck('value')->values()->all();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'company', 'phone', 'line_id', 'email', 'status'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
