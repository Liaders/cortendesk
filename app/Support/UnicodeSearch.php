<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Pdo\Sqlite;

final class UnicodeSearch
{
    /** Literal substring search, including Cyrillic case folding on SQLite. */
    public static function apply(Builder $query, array $columns, string $search): Builder
    {
        $connection = $query->getConnection();
        $function = 'LOWER';
        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $fold = static fn ($value) => mb_strtolower((string) $value, 'UTF-8');
            $pdo instanceof Sqlite
                ? $pdo->createFunction('cd_unicode_lower', $fold, 1, \PDO::SQLITE_DETERMINISTIC)
                : $pdo->sqliteCreateFunction('cd_unicode_lower', $fold, 1, \PDO::SQLITE_DETERMINISTIC);
            $function = 'cd_unicode_lower';
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim(mb_substr($search, 0, 255)), 'UTF-8')).'%';

        return $query->where(function ($query) use ($columns, $pattern, $connection, $function) {
            foreach ($columns as $column) {
                $query->orWhereRaw($function.'('.$connection->getQueryGrammar()->wrap($column).") LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
