<?php

use App\Modules\Service\Models\PersonalEvent;
use Illuminate\Support\Facades\Validator;

/*
 * Dates are held to a four-digit year on every form (App\Validation\Validator): a date field
 * without a max lets the year take six digits, and PHP reads "275760-11-25" as 2060-11-25.
 */

it('refuses a date whose year is not four digits, in every date rule', function (string $value, bool $date, bool $ymd) {
    expect(Validator::make(['d' => $value], ['d' => 'date'])->passes())->toBe($date)
        ->and(Validator::make(['d' => $value], ['d' => 'date_format:Y-m-d'])->passes())->toBe($ymd);
})->with([
    'six-digit year' => ['275760-11-25', false, false],
    'five-digit year' => ['20266-01-01', false, false],
    'three-digit year typed with a zero' => ['0202-01-01', false, false],
    'no such day' => ['2026-02-30', false, false],
    'a good day' => ['2026-11-25', true, true],
    'a good day and time' => ['2026-11-25T10:00', true, false],
    'the last day there is' => ['9999-12-31', true, true],
]);

it('leaves time-only formats alone', function () {
    expect(Validator::make(['t' => '09:30'], ['t' => 'date_format:H:i'])->passes())->toBeTrue();
});

it('does not save an appointment on a six-digit year', function () {
    $tech = userWithRole('technician');

    $this->actingAs($tech)->post('/my-work/events', ['title' => 'Visit', 'date' => '275760-11-25', 'all_day' => true])
        ->assertSessionHasErrors(['date' => 'วันที่ ต้องอยู่ในรูปแบบ วัน/เดือน/ปี ค.ศ. 4 หลัก']);
    expect(PersonalEvent::count())->toBe(0);

    $this->actingAs($tech)->post('/my-work/events', ['title' => 'Visit', 'date' => '2026-11-25', 'all_day' => true])->assertSessionHasNoErrors();
    expect(PersonalEvent::count())->toBe(1);
});

it('says what is wrong in words', function () {
    expect(Validator::make(['due_on' => '275760-11-25'], ['due_on' => 'date'], [], ['due_on' => 'วันครบกำหนด'])->errors()->first('due_on'))
        ->toBe('วันครบกำหนด ต้องเป็นวันที่ที่ถูกต้อง (ปี ค.ศ. 4 หลัก)')
        ->and(Validator::make(['at' => '9:3x'], ['at' => 'date_format:H:i'], [], ['at' => 'เวลา'])->errors()->first('at'))
        ->toBe('เวลา ต้องอยู่ในรูปแบบ ชั่วโมง:นาที เช่น 09:30');
});
