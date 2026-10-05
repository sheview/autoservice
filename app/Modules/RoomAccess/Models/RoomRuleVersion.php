<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One version of a room's access rules, as the customer gave them: the summary shown in the
 * accept popup, the full document (PDF), when it takes effect, who recorded it and where it came
 * from. Never changed once saved: a change is the next version (PublishRoomRules).
 */
class RoomRuleVersion extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia;

    public const UPDATED_AT = null;

    public const FILE = 'rules_file';

    public const FILE_MAX_KB = 10240;

    protected $fillable = ['server_room_id', 'version', 'summary', 'effective_on', 'received_from', 'received_on', 'note', 'created_by', 'created_by_name'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'summary' => 'array',
            'effective_on' => 'date',
            'received_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // A version accepted on a request must read the same for ever.
        static::updating(function () {
            throw new LogicException('Room rule versions are never changed: publish a new version.');
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ServerRoom::class, 'server_room_id')->withTrashed();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILE)->singleFile()->acceptsMimeTypes(['application/pdf']);
    }
}
