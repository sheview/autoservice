<?php

namespace App\Validation;

use Illuminate\Validation\Validator as BaseValidator;

/**
 * Laravel's validator, with dates held to a four-digit year (1000–9999) everywhere: "date" alone
 * lets "275760-11-25" through and PHP then reads it as 2060-11-25 (a browser date field without a
 * max takes a six-digit year), and both
 * "date" and "date_format:Y-m-d" let a mistyped "0202-01-01" through. Such a value is a typing
 * mistake, and a year past 9999 does not fit a database date column. Registered for every
 * validation in AppServiceProvider, so each form with a date rule refuses them without changes.
 */
class Validator extends BaseValidator
{
    public const MIN_YEAR = 1000;

    public const MAX_YEAR = 9999;

    public function validateDate($attribute, $value)
    {
        return parent::validateDate($attribute, $value) && $this->hasFourDigitYear($value);
    }

    public function validateDateFormat($attribute, $value, $parameters)
    {
        if (! parent::validateDateFormat($attribute, $value, $parameters)) {
            return false;
        }

        // A time alone ("H:i") has no year to check.
        return ! str_contains($parameters[0] ?? '', 'Y') || $this->hasFourDigitYear($value);
    }

    /** The format said in words a user knows ("Y-m-d" means nothing to them). */
    protected function replaceDateFormat($message, $attribute, $rule, $parameters)
    {
        $format = $parameters[0] ?? '';
        $said = __("validation.formats.{$format}");

        return str_replace(':format', $said === "validation.formats.{$format}" ? $format : $said, $message);
    }

    private function hasFourDigitYear(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            $year = (int) $value->format('Y');
        } else {
            // Year first (what a date field sends): exactly four digits. PHP reads "275760-11-25"
            // as 2060-11-25, so the parsed year alone would let it through as another date.
            if (preg_match('/^\s*[+-]?(\d+)-\d/', (string) $value, $match) && strlen($match[1]) !== 4) {
                return false;
            }
            $year = date_parse((string) $value)['year'];
            if ($year === false) {
                // Nothing that reads as a year ("tomorrow", "+1 week"): what PHP makes of it is today-ish.
                return true;
            }
        }

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }
}
