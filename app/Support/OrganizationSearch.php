<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class OrganizationSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        $needle = OrganizationIdentity::nameKey(mb_substr($search, 0, 255));
        if ($needle === '') {
            return $query;
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';

        return $query->where(fn ($q) => $q->whereRaw("name_key LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("inn LIKE ? ESCAPE '!'", [$pattern]));
    }
}
