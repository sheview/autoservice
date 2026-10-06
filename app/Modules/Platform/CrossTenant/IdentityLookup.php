<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Lets a callback read users of any tenant.
 *
 * Only for finding "who is this" before the tenant is known: login by email, loading the
 * user from the session, remember-me and password reset. The users RLS policy accepts rows
 * of any tenant while app.identity_lookup = 'on'. Never use it for listing or reporting.
 */
class IdentityLookup
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        if (self::$depth++ === 0) {
            self::set('on');
        }

        try {
            return $callback();
        } finally {
            if (--self::$depth === 0) {
                self::set('');
            }
        }
    }

    private static function set(string $value): void
    {
        // Without row level security (MariaDB) only the users' TenantScope is bypassed, by the caller.
        if (! Rls::supported()) {
            return;
        }
        DB::select('select set_config(?, ?, false)', [Rls::IDENTITY_LOOKUP, $value]);
    }
}
