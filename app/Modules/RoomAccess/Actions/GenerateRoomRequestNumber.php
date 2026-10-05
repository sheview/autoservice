<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The next number of a room access request of the current tenant: "SR-2569-00001" (Buddhist
 * year), a running number per year that two requests made at once cannot share.
 */
class GenerateRoomRequestNumber
{
    public function __construct(private TenantContext $context) {}

    public function handle(): string
    {
        $prefix = 'SR-'.(now()->year + 543);

        do {
            $number = DB::selectOne(
                'insert into room_access_sequences (tenant_id, prefix, last_number, created_at, updated_at)
                 values (?, ?, 1, now(), now())
                 on conflict (tenant_id, prefix)
                 do update set last_number = room_access_sequences.last_number + 1, updated_at = now()
                 returning last_number',
                [$this->context->id(), $prefix],
            )->last_number;

            $no = sprintf('%s-%05d', $prefix, $number);
        } while (RoomAccessRequest::withTrashed()->where('request_no', $no)->exists());

        return $no;
    }
}
