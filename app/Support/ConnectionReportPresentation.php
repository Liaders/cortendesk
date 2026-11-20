<?php

namespace App\Support;

use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ConnectionReportPresentation
{
    public const TIMEZONE = '+03:00';

    public static function device(?Device $device, ?string $id = null): string
    {
        $id = $device ? (string) $device->rustdesk_id : (string) $id;
        $alias = trim((string) $device?->alias);

        return $alias !== '' ? $alias.' ('.$id.')' : $id;
    }

    /** Resolve ownership only from devices already scoped to the report viewer. */
    public static function operator(AuditConnection $row, Collection $devices, ?Collection $webUsers = null): string
    {
        $user = self::operatorUser($row, $devices, $webUsers);

        return $user ? (trim((string) $user->name) ?: $user->username)
            : ($row->from_name ? $row->from_name.' ('.$row->from_peer.')' : (string) $row->from_peer);
    }

    public static function operatorKey(AuditConnection $row, Collection $devices, Collection $webUsers): string
    {
        $user = self::operatorUser($row, $devices, $webUsers);

        return $user ? 'user:'.$user->id : 'device:'.$row->from_peer;
    }

    private static function operatorUser(AuditConnection $row, Collection $devices, ?Collection $webUsers): ?User
    {
        // web-<user ID> is the server-generated identity used by the built-in web client.
        // Never resolve an arbitrary display name or a claimed login to a user.
        if (preg_match('/^web-([1-9][0-9]*)$/D', (string) $row->from_peer, $match)) {
            return $webUsers?->get($match[1]);
        }

        return $devices->get($row->from_peer)?->user;
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->setTimezone(self::TIMEZONE)->format('j.n.Y H:i:s') : '—';
    }
}
