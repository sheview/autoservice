<?php

namespace App\Modules\Service\Support;

use App\Modules\Tenancy\Support\CompanyCodes;

/**
 * Ticket numbers as customers see them. Inside, a number is per company (TK-2569-00001, unique with
 * tenant_id); shown outside — job sheet, tracking page, e-mails, alerts, lists — it carries the
 * company code after the prefix: TK001-2569-00001. The one place that turns one into the other.
 */
class TicketNumber
{
    private const INTERNAL = '/^TK-(\d{4})-(\d{5,})$/';

    private const SHOWN = '/^TK(\d{3,})-(\d{4})-(\d{5,})$/';

    /** TK-2569-00001 of company 001 -> TK001-2569-00001 (unchanged when the code is unknown). */
    public static function format(string $ticketNo, ?string $companyCode): string
    {
        if ($companyCode === null || ! preg_match(self::INTERNAL, $ticketNo, $m)) {
            return $ticketNo;
        }

        return "TK{$companyCode}-{$m[1]}-{$m[2]}";
    }

    /** The number as shown for a ticket of the given company. */
    public static function shown(string $ticketNo, ?int $tenantId): string
    {
        return self::format($ticketNo, CompanyCodes::of($tenantId));
    }

    /**
     * What a person typed: the company code it carries (null for the old form) and the number inside.
     * Spaces around and letter case do not matter. Null when it is no ticket number at all.
     *
     * @return array{company_code: string|null, ticket_no: string}|null
     */
    public static function parse(string $typed): ?array
    {
        $typed = strtoupper(trim($typed));

        if (preg_match(self::SHOWN, $typed, $m)) {
            return ['company_code' => $m[1], 'ticket_no' => "TK-{$m[2]}-{$m[3]}"];
        }
        if (preg_match(self::INTERNAL, $typed)) {
            return ['company_code' => null, 'ticket_no' => $typed];
        }

        return null;
    }

    /**
     * Text typed into a search box, with the company code taken out of a ticket number in it, so
     * "TK001-2569-000" still finds TK-2569-00012 of the company being searched.
     */
    public static function stored(string $typed): string
    {
        return preg_replace('/^TK\d{3,}-/i', 'TK-', trim($typed)) ?? trim($typed);
    }
}
