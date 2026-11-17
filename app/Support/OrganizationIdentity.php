<?php

namespace App\Support;

final class OrganizationIdentity
{
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name), 'UTF-8');
    }

    public static function validInn(?string $inn): bool
    {
        if ($inn === null || $inn === '') {
            return true;
        }
        if (! preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D', $inn) || preg_match('/^0+$/D', $inn)) {
            return false;
        }
        $check = function (array $weights) use ($inn): int {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += (int) $inn[$i] * $weight;
            }

            return $sum % 11 % 10;
        };

        return strlen($inn) === 10
            ? $check([2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[9]
            : $check([7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[10]
                && $check([3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === (int) $inn[11];
    }
}
