<?php

namespace App\Modules\Document\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates for PDF documents: Thai month names and the Buddhist year, Bangkok time
 * (the internal UI keeps the Gregorian year).
 */
class ThaiDate
{
    private const MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    private const LONG_MONTHS = [
        '', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    /** "15 มิ.ย. 2569" or, with $time, "15 มิ.ย. 2569 09:30"; "-" for no date. */
    public static function format(DateTimeInterface|string|null $date, bool $time = false): string
    {
        if ($date === null || $date === '') {
            return '-';
        }

        $date = CarbonImmutable::parse($date)->setTimezone('Asia/Bangkok');
        $text = $date->day.' '.self::MONTHS[$date->month].' '.($date->year + 543);

        return $time ? $text.' '.$date->format('H:i') : $text;
    }

    /** "9 ตุลาคม 2567", the month written out (stickers, letters); "-" for no date. */
    public static function long(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '-';
        }

        $date = CarbonImmutable::parse($date)->setTimezone('Asia/Bangkok');

        return $date->day.' '.self::LONG_MONTHS[$date->month].' '.($date->year + 543);
    }
}
