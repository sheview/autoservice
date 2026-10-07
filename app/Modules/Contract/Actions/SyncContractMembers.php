<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Identity\Actions\StaffList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the team of a project (contract): the staff given, no one else. Only active staff of the
 * company may join; those already on it stay even if since made inactive.
 */
class SyncContractMembers
{
    public function __construct(private StaffList $staffList) {}

    /**
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function handle(Contract $contract, array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        return DB::transaction(function () use ($contract, $userIds) {
            $current = $contract->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $added = array_values(array_diff($userIds, $current));
            $removed = array_values(array_diff($current, $userIds));

            $staff = array_column($this->staffList->handle(), 'id');
            if (array_diff($added, $staff) !== []) {
                throw ValidationException::withMessages(['user_ids' => __('contract.members.not_staff')]);
            }

            $contract->members()->whereIn('user_id', $removed)->delete();
            foreach ($added as $userId) {
                $contract->members()->create(['user_id' => $userId]);
            }

            if ($added !== [] || $removed !== []) {
                activity()->performedOn($contract)->event('members_updated')
                    ->withProperties(['added' => $added, 'removed' => $removed])->log('แก้ไขทีมโครงการ');
            }

            return ['added' => $added, 'removed' => $removed];
        });
    }
}
