<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Services\ClientOrganizations;
use App\Services\SessionWorkGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionWorkGroupController extends Controller
{
    public function show(Request $request, string $guid): JsonResponse
    {
        [$row, $device] = $this->session($request, $guid);
        $bindings = ClientOrganizations::bindings($device);
        $permissions = ClientOrganizations::permissions($request->user());
        $unconfirmed = in_array($row->work_group_status, ['required', 'ungrouped'], true);

        return response()->json([
            'api_version' => 2,
            'device_has_organization' => $bindings !== [],
            'bindings' => $bindings,
            'permissions' => $permissions + [
                'can_assign' => $row->work_group_status === 'required' || ($unconfirmed && $permissions['can_attach']),
                'can_attach_and_assign' => $unconfirmed && $permissions['can_attach'],
            ],
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
        $data = $request->validate([
            'group_id' => 'required|integer|min:1',
            'attach_device_if_needed' => 'sometimes|boolean',
        ]);

        return DB::transaction(function () use ($request, $guid, $data) {
            [$row, $device] = $this->session($request, $guid);
            // Serialize with device attachment as well as competing session choices.
            $device = ClientOrganizations::device($request->user(), $device->rustdesk_id, true);
            $row = AuditConnection::whereKey($row->id)->lockForUpdate()->firstOrFail();
            // A retry stays idempotent even after the configuration or name changes.
            if (in_array($row->work_group_status, ['selected', 'automatic'], true)) {
                abort_unless((int) $row->work_group_id === (int) $data['group_id'], 409);

                return response()->json(['saved' => true]);
            }
            $attach = (bool) ($data['attach_device_if_needed'] ?? false);
            abort_unless($row->work_group_status === 'required' || ($attach && $row->work_group_status === 'ungrouped'), 409);
            if ($attach) {
                $group = ClientOrganizations::attach($request->user(), $device, (int) $data['group_id']);
            } else {
                $group = SessionWorkGroups::choices($device)->firstWhere('id', (int) $data['group_id']);
                abort_if($group === null, 422);
            }
            $updated = AuditConnection::whereKey($row->id)->where('work_group_status', $row->work_group_status)->update([
                'work_group_id' => $group->id,
                'work_group_name' => $group->name,
                'work_group_status' => 'selected',
            ]);
            abort_unless($updated === 1, 409);

            return response()->json(['saved' => true]);
        }, 5);
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
