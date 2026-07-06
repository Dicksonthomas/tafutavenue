<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class CrEmailGenerator
{
    public const EMAIL_DOMAIN = 'mustudent.ac.tz';

    public const MIN_INTAKE_YEAR = 2022;

    /**
     * Mwaka mpya wa usajili (intake) huanza rasmi Oktoba. Kabla ya hapo,
     * Reg No za mwaka huo bado hazijaruhusiwa (wanafunzi hawajaanza rasmi).
     */
    public static function maxIntakeYear(): int
    {
        $now = Carbon::now();

        return $now->month >= 10 ? $now->year : $now->year - 1;
    }

    /**
     * Tengeneza email kutoka Fullname + Reg No, mfano:
     *   name = "Dickson Musa Thomas", reg_no = "14322055/T.25"
     *   -> "dickson.thomas25@mustudent.ac.tz"
     *
     * Reg No lazima iishie na ".YY" (mwaka wa mwisho wa usajili, tarakimu 2).
     * Mwaka ukiwa chini ya MIN_INTAKE_YEAR, tunatupa InvalidArgumentException
     * ili Admin awasiliane na CR na kumsajili kwa namna nyingine (mfano email ya mkono).
     *
     * @return array{email: string, year: int}
     */
    public static function generate(string $fullName, string $regNo): array
    {
        if (! preg_match('/\.(\d{2})$/', trim($regNo), $matches)) {
            throw new InvalidArgumentException(
                'Reg No si sahihi. Muundo sahihi ni mfano: 14322055/T.25 (lazima uishie na nukta na tarakimu mbili za mwaka).'
            );
        }

        $year = 2000 + (int) $matches[1];

        if ($year < self::MIN_INTAKE_YEAR) {
            throw new InvalidArgumentException(
                "Reg No hii ina mwaka wa usajili wa nyuma sana ({$year}). Tafadhali wasiliane na Admin ili akusajili kwa namna nyingine."
            );
        }

        $maxYear = self::maxIntakeYear();

        if ($year > $maxYear) {
            throw new InvalidArgumentException(
                "Reg No ya mwaka {$year} bado haijaruhusiwa. Usajili wa mwaka huo unafunguliwa rasmi Oktoba {$year}."
            );
        }

        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (count($parts) < 1) {
            throw new InvalidArgumentException('Jina si sahihi.');
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
