<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a branch that no (non-deleted) user, asset or ticket belongs to.
 */
class DeleteBranch
{
    /** Tables of other modules that point at a branch (read by table, not by their models). */
    private const USED_BY = ['users', 'assets', 'tickets'];

    public function handle(Branch $branch): void
    {
        foreach (self::USED_BY as $table) {
            $used = DB::table($table)
                ->where('tenant_id', $branch->tenant_id)
                ->where('branch_id', $branch->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($used) {
                throw ValidationException::withMessages(['branch' => __('tenancy.branches.in_use')]);
            }
        }

        $branch->delete();
    }
}
