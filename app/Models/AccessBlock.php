<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class AccessBlock extends Model
{
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function enforcementReady(): bool
    {
        $at = Cache::get('statusdesk:firewall:applied_at');

        return is_string($at) && CarbonImmutable::parse($at)->gt(now()->subSeconds(30));
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function normalizeIp(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $bytes = inet_pton($ip);
        if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $bytes = substr($bytes, 12);
        }

        return inet_ntop($bytes);
    }

    public static function denies(string $kind, ?string $value): bool
    {
        return $value !== null && $value !== '' && static::effective()->where('kind', $kind)->where('value', $value)->exists();
    }
}
