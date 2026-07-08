<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class CrEmailGenerator
{
    public const EMAIL_DOMAIN = 'mustudent.ac.tz';

    public const MIN_INTAKE_YEAR = 2022;

    /**
     * A new intake year officially starts in October. Before that, Reg No
     * entries for that year are not yet allowed (students haven't officially started).
     */
    public static function maxIntakeYear(): int
    {
        $now = Carbon::now();

        return $now->month >= 10 ? $now->year : $now->year - 1;
    }

    /**
     * Generate an email from Fullname + Reg No, e.g.:
     *   name = "Dickson Musa Thomas", reg_no = "14322055/T.25"
     *   -> "dickson.thomas25@mustudent.ac.tz"
     *
     * The Reg No must end with ".YY" (the last two digits of the intake
     * year). If the year is before MIN_INTAKE_YEAR, we throw an
     * InvalidArgumentException so the Admin can contact the CR and register
     * them another way (e.g. a manual email).
     *
     * @return array{email: string, year: int}
     */
    public static function generate(string $fullName, string $regNo): array
    {
        if (! preg_match('/\.(\d{2})$/', trim($regNo), $matches)) {
            throw new InvalidArgumentException(
                'Reg No is invalid. The correct format is e.g.: 14322055/T.25 (must end with a dot and two digits for the year).'
            );
        }

        $year = 2000 + (int) $matches[1];

        if ($year < self::MIN_INTAKE_YEAR) {
            throw new InvalidArgumentException(
                "This Reg No has a registration year that is too old ({$year}). Please contact the Admin to register you another way."
            );
        }

        $maxYear = self::maxIntakeYear();

        if ($year > $maxYear) {
            throw new InvalidArgumentException(
                "Reg No for year {$year} is not yet allowed. Registration for that year officially opens in October {$year}."
            );
        }

        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) < 1) {
            throw new InvalidArgumentException('Name is invalid.');
        }

        $first = self::slug($parts[0]);
        $last = self::slug($parts[count($parts) - 1]);

        $yearSuffix = $matches[1];
        $localPart = count($parts) > 1
            ? "{$first}.{$last}{$yearSuffix}"
            : "{$first}{$yearSuffix}";

        return [
            'email' => "{$localPart}@".self::EMAIL_DOMAIN,
            'year' => $year,
        ];
    }

    private static function slug(string $value): string
    {
        $value = strtolower($value);

        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }
}
