<?php

use App\Modules\Service\Support\SlaCalendar;
use Carbon\CarbonImmutable;

// 2026-06-12 is a Friday, 2026-06-13 Saturday, 2026-06-15 Monday.
function slaAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Asia/Bangkok');
}

it('adds 8x5 business minutes over the weekend', function () {
    $calendar = new SlaCalendar;

    expect($calendar->addMinutes(slaAt('2026-06-12 16:00'), 60, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-12 17:00')
        ->and($calendar->addMinutes(slaAt('2026-06-12 16:00'), 120, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-15 09:00')
        ->and($calendar->addMinutes(slaAt('2026-06-13 10:00'), 30, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-15 08:30')
        ->and($calendar->addMinutes(slaAt('2026-06-15 06:00'), 30, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-15 08:30')
        ->and($calendar->addMinutes(slaAt('2026-06-15 18:00'), 30, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-16 08:30')
        // three full days of 9 hours
        ->and($calendar->addMinutes(slaAt('2026-06-15 08:00'), 27 * 60, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-17 17:00');
});

it('skips holidays for 8x5 and 12x6 but not for 24x7', function () {
    $calendar = new SlaCalendar(['2026-06-15']);

    expect($calendar->addMinutes(slaAt('2026-06-12 16:00'), 120, '8x5')->format('Y-m-d H:i'))->toBe('2026-06-16 09:00')
        ->and($calendar->addMinutes(slaAt('2026-06-13 19:00'), 120, '12x6')->format('Y-m-d H:i'))->toBe('2026-06-16 09:00')
        ->and($calendar->addMinutes(slaAt('2026-06-14 23:00'), 120, '24x7')->format('Y-m-d H:i'))->toBe('2026-06-15 01:00');
});

it('works on Saturdays for 12x6 until 20:00', function () {
    $calendar = new SlaCalendar;

    expect($calendar->addMinutes(slaAt('2026-06-13 19:00'), 60, '12x6')->format('Y-m-d H:i'))->toBe('2026-06-13 20:00')
        ->and($calendar->addMinutes(slaAt('2026-06-13 19:00'), 120, '12x6')->format('Y-m-d H:i'))->toBe('2026-06-15 09:00');
});

it('counts business minutes between two moments', function () {
    $calendar = new SlaCalendar(['2026-06-15']);

    expect($calendar->minutesBetween(slaAt('2026-06-12 16:00'), slaAt('2026-06-16 09:00'), '8x5'))->toBe(120)
        ->and($calendar->minutesBetween(slaAt('2026-06-12 07:00'), slaAt('2026-06-12 20:00'), '8x5'))->toBe(540)
        ->and($calendar->minutesBetween(slaAt('2026-06-13 10:00'), slaAt('2026-06-14 10:00'), '8x5'))->toBe(0)
        ->and($calendar->minutesBetween(slaAt('2026-06-13 10:00'), slaAt('2026-06-14 10:00'), '24x7'))->toBe(1440)
        ->and($calendar->minutesBetween(slaAt('2026-06-16 10:00'), slaAt('2026-06-16 09:00'), '8x5'))->toBe(0);
});

it('rejects an unknown service window', function () {
    (new SlaCalendar)->addMinutes(slaAt('2026-06-12 10:00'), 60, '9x9');
})->throws(InvalidArgumentException::class);
