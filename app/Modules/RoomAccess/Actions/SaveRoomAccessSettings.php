<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Support\RoomAccessSettings;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Saves the company's own terms (added after every room's rules) and how long ID card numbers
 * are kept, with who changed them in the activity log.
 */
class SaveRoomAccessSettings
{
    /**
     * @param  array{company_terms: list<string>, id_retention_days: int}  $data  validated
     */
    public function handle(Tenant $tenant, array $data, User $actor): void
    {
        $old = RoomAccessSettings::of($tenant);
        $settings = $tenant->settings ?? [];
        $settings['room_access'] = [
            'company_terms' => array_values(array_filter(array_map('trim', $data['company_terms']), fn (string $line) => $line !== '')),
            'id_retention_days' => (int) $data['id_retention_days'],
        ];
        $tenant->settings = $settings;
        $tenant->save();

        activity()->performedOn($tenant)->causedBy($actor)->event('room_access_settings_updated')
            ->withProperties(['old' => $old, 'attributes' => $settings['room_access']])
            ->log(__('room_access.log.settings_updated'));
    }
}
