<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Services\SessionWorkGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionWorkGroupController extends Controller
{
    public function show(Request $request, string $guid): JsonResponse
    {
        [$row, $device] = $this->session($request, $guid);

        return response()->json([
            'status' => $row->work_group_status,
            'group' => $row->work_group_id ? ['id' => $row->work_group_id, 'name' => $row->work_group_name] : null,
            'groups' => $row->work_group_status === 'required'
                ? SessionWorkGroups::choices($device)->map(fn ($group) => ['id' => $group->id, 'name' => $group->name])
                : [],
        ]);
    }

    public function choices(Request $request, string $id): JsonResponse
    {
        $device = Device::visibleTo($request->user())->where('rustdesk_id', $id)->firstOrFail();
        $groups = SessionWorkGroups::choices($device);

        return response()->json([
            'groups' => $groups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name]),
            'selection_required' => $groups->count() > 1,
        ]);
    }

    public function select(Request $request, string $guid): JsonResponse
    {
        $data = $request->validate(['group_id' => 'required|integer|min:1']);
        [$row, $device] = $this->session($request, $guid);

        // A retry stays idempotent even after the configuration or name changes.
        if (in_array($row->work_group_status, ['selected', 'automatic'], true)) {
            abort_unless((int) $row->work_group_id === (int) $data['group_id'], 409);

            return response()->json(['saved' => true]);
        }
        abort_unless($row->work_group_status === 'required', 409);
        $group = SessionWorkGroups::choices($device)->firstWhere('id', (int) $data['group_id']);
        abort_if($group === null, 422);
        $updated = AuditConnection::whereKey($row->id)->where('work_group_status', 'required')->update([
            'work_group_id' => $group->id,
            'work_group_name' => $group->name,
            'work_group_status' => 'selected',
        ]);
        if (! $updated) {
            $row->refresh();
            abort_unless($row->work_group_status === 'selected' && (int) $row->work_group_id === $group->id, 409);
        }

        return response()->json(['saved' => true]);
    }

    private function session(Request $request, string $guid): array
    {
        $token = $request->attributes->get('client_token');
        abort_unless($token instanceof ClientToken && $token->device_id, 403);
        $row = AuditConnection::where('guid', $guid)->where('from_peer', $token->device_id)->firstOrFail();
        $device = Device::visibleTo($request->user())->where('rustdesk_id', $row->rustdesk_id)->firstOrFail();

        return [$row, $device];
    }
}
