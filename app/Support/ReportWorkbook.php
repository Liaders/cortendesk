<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Shuchkin\SimpleXLSXGen;

/** Safe, consistently aligned cells for the connection report workbook. */
final class ReportWorkbook
{
    public static function text(mixed $value, bool $heading = false): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
        // The library's raw-tag matcher accepts CR; XML normalizes it back to LF.
        $value = str_replace(["\r\n", "\n"], "\r", (string) $value);
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = $heading ? '<style bgcolor="#E8EEF5" color="#243247" height="28"><b>' : '';

        return $style.'<left><top><wraptext><raw>'.$escaped.'</raw></wraptext></top></left>'.($heading ? '</b></style>' : '');
    }

    public static function number(int|float $value, string $format = '0'): string
    {
        return '<style nf="'.$format.'"><left><top>'.$value.'</top></left></style>';
    }

    public static function duration(int $seconds): string
    {
        return self::number($seconds / 86400, '[h]:mm:ss');
    }

    public static function date(?CarbonInterface $date, bool $time = false): ?string
    {
        if ($date === null) {
            return null;
        }
        $serial = SimpleXLSXGen::date2excel($date->year, $date->month, $date->day, $date->hour, $date->minute, $date->second);

        return self::number($serial, $time ? 'd.m.yyyy hh:mm:ss' : 'd.m.yyyy');
    }

    public static function heading(array $labels): array
    {
        return array_map(fn ($label) => self::text($label, true), $labels);
    }
}
