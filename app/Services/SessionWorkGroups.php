<?php

namespace App\Services;

use App\Models\AuditConnection;
use App\Models\Device;
use Illuminate\Support\Collection;

class SessionWorkGroups
{
    /** These choices are accounting metadata, never visibility grants. */
    public static function choices(Device $device): Collection
    {
        return $device->workGroups->concat($device->group ? [$device->group] : [])
            ->unique('id')->sortBy('name')->values();
    }

    public static function snapshot(AuditConnection $row): void
    {
        if ($row->work_group_status !== null || ! $row->from_peer) {
            return;
        }
        $device = Device::where('rustdesk_id', $row->rustdesk_id)->first();
        if ($device !== null && self::choices($device)->count() > 1) {
            $row->work_group_status = 'required';

            return;
        }
        $group = $device === null ? null : self::choices($device)->first();
        $row->forceFill([
            'work_group_id' => $group?->id,
            'work_group_name' => $group?->name,
            'work_group_status' => $group ? 'automatic' : 'ungrouped',
        ]);
    }

    public static function label(AuditConnection $row, ?string $locale = null): string
    {
        return match ($row->work_group_status) {
            'required' => __('work_groups.unassigned', [], $locale),
            'automatic', 'selected' => (string) $row->work_group_name,
            'ungrouped' => __('report.ungrouped', [], $locale),
            default => $row->controlledDevice?->group?->name ?? __('report.ungrouped', [], $locale),
        };
    }
}
