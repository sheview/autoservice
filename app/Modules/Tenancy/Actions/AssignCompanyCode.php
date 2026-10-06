<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new company the next company code: 001, 002, ... (more digits once past 999; codes given
 * before keep theirs). Codes are counted in company_codes, which never forgets one, so a code is
 * never given twice even after its company is deleted. The platform tenant gets none.
 */
class AssignCompanyCode
{
    public const MIN_DIGITS = 3;

    public function handle(Tenant $tenant): void
    {
        if ($tenant->is_platform || filled($tenant->company_code)) {
            return;
        }

        DB::transaction(function () use ($tenant) {
            // One at a time: locking the highest number makes two new companies wait for each other
            // (and the unique number refuses a second one that slipped through).
            $number = (int) DB::table('company_codes')->lockForUpdate()->max('number') + 1;
            $code = str_pad((string) $number, self::MIN_DIGITS, '0', STR_PAD_LEFT);

            DB::table('company_codes')->insert(['number' => $number, 'code' => $code, 'tenant_id' => null, 'created_at' => now()]);
            $tenant->company_code = $code;
        });
    }
}
