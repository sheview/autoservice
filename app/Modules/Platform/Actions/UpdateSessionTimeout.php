<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\SessionTimeout;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Sets the idle time before sign-out for the whole platform (see SessionTimeout), and writes the
 * change to the platform's log with who made it.
 */
class UpdateSessionTimeout
{
    public function handle(int $minutes, User $actor): void
    {
        $platform = Tenant::where('is_platform', true)->sole();
        $old = SessionTimeout::minutes();

        $platform->settings = [...$platform->settings, SessionTimeout::SETTING => $minutes];
        $platform->save();
        SessionTimeout::forget();

        if ($old !== $minutes) {
            activity('platform')
                ->causedBy($actor)
                ->performedOn($platform)
                ->event('session_timeout_updated')
                ->withProperties(['old' => [SessionTimeout::SETTING => $old], 'attributes' => [SessionTimeout::SETTING => $minutes]])
                ->log('ตั้งเวลาหมดอายุการเข้าสู่ระบบ');
        }
    }
}
