<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'current' => 'sometimes|integer|min:1|max:2147483647',
            'pageSize' => 'sometimes|integer|min:1|max:500',
        ]);
        $page = (int) ($input['current'] ?? 1);
        $size = (int) ($input['pageSize'] ?? 100);

        // Scope both branches before combining membership: a work group grants no access.
        $visible = Device::visibleTo($request->user())->select('devices.id', 'devices.rustdesk_id', 'devices.device_group_id');
        $primary = DB::query()->fromSub(clone $visible, 'visible_devices')->whereNotNull('visible_devices.device_group_id')
            ->select('visible_devices.device_group_id as organization_id', 'visible_devices.rustdesk_id as peer_id');
        $additional = DB::query()->fromSub(clone $visible, 'visible_devices')->join('device_work_groups', 'device_work_groups.device_id', '=', 'visible_devices.id')
            ->select('device_work_groups.device_group_id as organization_id', 'visible_devices.rustdesk_id as peer_id');
        $memberships = $primary->union($additional);
        $organizations = DeviceGroup::whereIn('id', DB::query()->fromSub($memberships, 'memberships')->select('organization_id'));
        $total = (clone $organizations)->count();
        $rows = $organizations->orderBy('name')->orderBy('id')->forPage($page, $size)->get(['id', 'name']);
        $peers = DB::query()->fromSub($memberships, 'memberships')->whereIn('organization_id', $rows->modelKeys())
            ->orderBy('peer_id')->get()->groupBy('organization_id');

        return response()->json([
            'total' => $total,
            'data' => $rows->map(fn (DeviceGroup $group) => [
                'id' => (int) $group->id,
                'name' => $group->name,
                'peer_ids' => $peers->get($group->id, collect())->map(fn ($peer) => (string) $peer->peer_id)->values()->all(),
            ])->values()->all(),
        ]);
    }
}
