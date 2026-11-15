<?php

namespace W4os\Grav;

/** Numbers as a language writes them (intl): 1 234,5 in French, 1,234.5 in English. */
final class Format
{
    public static function number(float|int $value, string $locale, int $decimals = 0): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);

        return (string) $formatter->format($value);
    }

    /** An area in square kilometers with its unit: two decimals under 100 km² (a region of 256 m is 0.07 km²). */
    public static function area(float|int $squareKilometers, string $locale): string
    {
        return self::number($squareKilometers, $locale, $squareKilometers < 100 ? 2 : 0)."\u{a0}km²";
    }
}
