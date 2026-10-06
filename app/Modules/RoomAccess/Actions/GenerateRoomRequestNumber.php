<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\Tenancy\Support\Counter;
use App\Modules\Tenancy\Support\TenantContext;

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
            $number = Counter::next('room_access_sequences', 'prefix', $prefix, $this->context->id());

            $no = sprintf('%s-%05d', $prefix, $number);
        } while (RoomAccessRequest::withTrashed()->where('request_no', $no)->exists());

        return $no;
    }
}
