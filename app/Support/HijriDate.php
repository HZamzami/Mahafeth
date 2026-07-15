<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use IntlCalendar;
use IntlDateFormatter;

/**
 * Gregorian ↔ Hijri conversion on the Umm al-Qura calendar via PHP's intl
 * extension — the official Saudi civil calendar, and the one zakat hawl
 * anniversaries live on. No external dependency required.
 */
class HijriDate
{
    private const CALENDAR = '-u-ca-islamic-umalqura';

    /**
     * @return array{year: int, month: int, day: int} month and day are 1-based
     */
    public static function toHijri(CarbonInterface $date): array
    {
        $calendar = self::calendar();
        $calendar->setTime($date->getTimestampMs());

        return [
            'year' => $calendar->get(IntlCalendar::FIELD_YEAR),
            'month' => $calendar->get(IntlCalendar::FIELD_MONTH) + 1,
            'day' => $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /**
     * The Gregorian date of a Hijri calendar day. Day numbers beyond the
     * month's actual length (a 30th in a 29-day month) clamp to its last day.
     */
    public static function gregorian(int $year, int $month, int $day): Carbon
    {
        $calendar = self::calendar();
        $calendar->setDateTime($year, $month - 1, 1, 0, 0, 0);
        $calendar->setDateTime($year, $month - 1, min($day, $calendar->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH)), 0, 0, 0);

        return Carbon::createFromTimestamp(intdiv((int) $calendar->getTime(), 1000), 'UTC')->startOfDay();
    }

    /**
     * The next Gregorian occurrence (on or after $after) of a recurring
     * Hijri month/day anniversary.
     */
    public static function nextGregorian(int $month, int $day, ?CarbonInterface $after = null): Carbon
    {
        $after = ($after ?? today())->copy()->startOfDay();
        $year = self::toHijri($after)['year'];

        while (true) {
            $candidate = self::gregorian($year, $month, $day);

            if ($candidate->gte($after)) {
                return $candidate;
            }

            $year++;
        }
    }

    /**
     * A Gregorian date rendered as a Hijri date string in the given locale,
     * e.g. "15 Ramadan 1448" / "١٥ رمضان ١٤٤٨".
     */
    public static function format(CarbonInterface $date, ?string $locale = null): string
    {
        $formatter = new IntlDateFormatter(
            ($locale ?? app()->getLocale()).self::CALENDAR,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::TRADITIONAL,
            'd MMMM y',
        );

        return $formatter->format($date->getTimestamp());
    }

    /**
     * Localized Hijri month names, keyed 1–12.
     *
     * @return array<int, string>
     */
    public static function monthNames(?string $locale = null): array
    {
        $formatter = new IntlDateFormatter(
            ($locale ?? app()->getLocale()).self::CALENDAR,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::TRADITIONAL,
            'MMMM',
        );

        $year = self::toHijri(today())['year'];
        $names = [];

        foreach (range(1, 12) as $month) {
            $names[$month] = $formatter->format(self::gregorian($year, $month, 1)->getTimestamp());
        }

        return $names;
    }

    private static function calendar(): IntlCalendar
    {
        return IntlCalendar::createInstance('UTC', 'en'.self::CALENDAR);
    }
}
