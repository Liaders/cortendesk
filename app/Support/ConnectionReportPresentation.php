<?php

namespace App\Support;

use App\Models\AuditConnection;
use App\Models\Device;
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
    public static function operator(AuditConnection $row, Collection $devices): string
    {
        $user = $devices->get($row->from_peer)?->user;

        return $user ? (trim((string) $user->name) ?: $user->username) : ($row->from_name ?: (string) $row->from_peer);
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->setTimezone(self::TIMEZONE)->format('j.n.Y H:i:s') : '—';
    }
}
