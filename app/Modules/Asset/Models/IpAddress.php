<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An address of a subnet that something happened to. Without a row an address is free.
 * "conflict" is never stored: it is worked out from the assets that carry the address (IpTable).
 * Rows are kept when an address is released, so its history and tickets stay.
 */
class IpAddress extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_EXCLUDED = 'excluded';

    /** Shown and filtered; worked out, not stored. */
    public const STATUS_CONFLICT = 'conflict';

    public const STATUSES = [self::STATUS_AVAILABLE, self::STATUS_IN_USE, self::STATUS_RESERVED, self::STATUS_EXCLUDED, self::STATUS_CONFLICT];

    protected $fillable = [
        'subnet_id', 'ip', 'ip_int', 'status', 'asset_id', 'hostname', 'mac_address',
        'responsible_id', 'in_use_since', 'notes',
    ];

    protected function casts(): array
    {
        return ['ip_int' => 'integer', 'asset_id' => 'integer', 'responsible_id' => 'integer', 'in_use_since' => 'date'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class)->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(IpReservation::class)->latest('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(IpAssetHistory::class)->latest('id');
    }
}
