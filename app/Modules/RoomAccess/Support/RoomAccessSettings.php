<?php

namespace App\Modules\RoomAccess\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * A company's own settings of server room requests, kept in tenants.settings "room_access":
 * its own terms, added after each room's rules in the accept popup (never in their place), and
 * for how many days ID card numbers of entrants are kept after the visit ends.
 */
class RoomAccessSettings
{
    public const PERMISSION = 'room-access.manage';

    public const ID_RETENTION_DAYS = 90;

    public const MAX_TERMS = 10;

    /**
     * @return array{company_terms: list<string>, id_retention_days: int}
     */
    public static function of(?Tenant $tenant): array
    {
        $set = $tenant?->settings['room_access'] ?? [];

        return [
            'company_terms' => array_values(array_filter((array) ($set['company_terms'] ?? __('room_access.default_company_terms')), 'filled')),
            'id_retention_days' => (int) ($set['id_retention_days'] ?? self::ID_RETENTION_DAYS),
        ];
    }
}
