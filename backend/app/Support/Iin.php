<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class Iin
{
    private const W1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

    private const W2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];

    public static function checksum(string $first11): ?int
    {
        if (! preg_match('/^\d{11}$/', $first11)) {
            return null;
        }
        $digits = array_map('intval', str_split($first11));
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * self::W1[$i];
        }
        $control = $sum % 11;
        if ($control === 10) {
            $sum = 0;
            foreach ($digits as $i => $d) {
                $sum += $d * self::W2[$i];
            }
            $control = $sum % 11;
            if ($control === 10) {
                return null;
            }
        }

        return $control;
    }

    public static function hasValidChecksum(string $value): bool
    {
        if (! preg_match('/^\d{12}$/', $value)) {
            return false;
        }
        $control = self::checksum(substr($value, 0, 11));

        return $control !== null && $control === (int) $value[11];
    }

    public static function isValid(string $iin): bool
    {
        return self::hasValidChecksum($iin) && self::birthDate($iin) !== null;
    }

    public static function birthDate(string $iin): ?CarbonImmutable
    {
        if (! preg_match('/^\d{12}$/', $iin)) {
            return null;
        }
        $centuryDigit = (int) $iin[6];
        $century = match ($centuryDigit) {
            1, 2 => 1800,
            3, 4 => 1900,
            5, 6 => 2000,
            default => null,
        };
        if ($century === null) {
            return null;
        }
        $year = $century + (int) substr($iin, 0, 2);
        $month = (int) substr($iin, 2, 2);
        $day = (int) substr($iin, 4, 2);
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    public static function gender(string $iin): ?string
    {
        if (! preg_match('/^\d{12}$/', $iin)) {
            return null;
        }
        $d = (int) $iin[6];
        if ($d < 1 || $d > 6) {
            return null;
        }

        return $d % 2 === 1 ? 'male' : 'female';
    }

    public static function isValidBin(string $bin): bool
    {
        if (! self::hasValidChecksum($bin)) {
            return false;
        }
        $month = (int) substr($bin, 2, 2);
        $type = (int) $bin[4];

        return $month >= 1 && $month <= 12 && in_array($type, [4, 5, 6], true);
    }

    public static function make(CarbonImmutable $birth, bool $male, int $serial): ?string
    {
        $centuryDigit = match (true) {
            $birth->year >= 2000 => $male ? 5 : 6,
            $birth->year >= 1900 => $male ? 3 : 4,
            default => $male ? 1 : 2,
        };
        $first = $birth->format('ymd').$centuryDigit.str_pad((string) ($serial % 10000), 4, '0', STR_PAD_LEFT);
        $control = self::checksum($first);

        return $control === null ? null : $first.$control;
    }
}
