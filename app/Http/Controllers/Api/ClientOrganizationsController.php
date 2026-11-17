<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Rules\OrganizationInn;
use App\Services\ClientOrganizations;
use App\Support\OrganizationSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientOrganizationsController extends Controller
{
    public function options(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'q' => 'sometimes|nullable|string|max:255',
            'current' => 'sometimes|integer|min:1|max:2147483647',
            'pageSize' => 'sometimes|integer|min:1|max:500',
        ]);
        $device = ClientOrganizations::device($request->user(), $id);
        $query = OrganizationSearch::apply(ClientOrganizations::attachable($request->user(), $device), $data['q'] ?? '');
        $total = (clone $query)->count();
        $size = (int) ($data['pageSize'] ?? 100);
        $page = (int) ($data['current'] ?? 1);
        $rows = $query->orderBy('name')->orderBy('id')->forPage($page, $size)->get(['id', 'name', 'inn']);
        $bindings = ClientOrganizations::bindings($device);

        return response()->json([
            'api_version' => 2, 'device_has_organization' => $bindings !== [], 'bindings' => $bindings,
            'permissions' => ClientOrganizations::permissions($request->user()), 'total' => $total,
            'data' => $rows->values()->all(),
        ]);
    }

    public function createOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => 'required|string|max:255']);
        ClientOrganizations::device($request->user(), $data['device_id']);
        $allowed = ClientOrganizations::permissions($request->user())['can_create'];
        $groups = $allowed ? ClientOrganizations::userGroups($request->user()) : collect();
        $own = array_values(array_intersect($groups->pluck('id')->all(), $request->user()->userGroupIds()));

        return response()->json([
            'api_version' => 2, 'can_create' => $allowed,
            'fields' => ['name' => ['required' => true, 'max_length' => 255], 'note' => ['required' => false, 'max_length' => 500], 'inn' => ['required' => false, 'max_length' => 12]],
            'user_groups' => $groups->values()->all(),
            'default_user_group_ids' => count($own) === 1 ? $own : [],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'device_id' => 'required|string|max:255',
            'name' => 'required|string|max:255', 'note' => 'nullable|string|max:500',
            'inn' => ['sometimes', 'nullable', 'string', new OrganizationInn],
            'user_group_ids' => 'present|array', 'user_group_ids.*' => 'required|integer|min:1|distinct',
        ]);
        ClientOrganizations::device($request->user(), $data['device_id']);
        abort_unless(ClientOrganizations::permissions($request->user())['can_create'], 403);
        $ids = array_map('intval', $data['user_group_ids']);
        sort($ids, SORT_NUMERIC);
        $allowed = ClientOrganizations::userGroups($request->user())->pluck('id')->all();
        abort_unless(array_diff($ids, $allowed) === [], 403, 'User group grant is not allowed.');
        $payload = ['device_id' => $data['device_id'], 'name' => $data['name'], 'note' => $data['note'] ?? null, 'user_group_ids' => $ids];
        // Preserve pre-INN receipt hashes for clients that omit the optional field.
        if (array_key_exists('inn', $data)) {
            $payload['inn'] = $data['inn'];
        }
        $result = ClientOrganizations::once($request->user(), 'create', $data['request_id'], $payload, function () use ($payload) {
            validator($payload, ['inn' => ['nullable', Rule::unique('device_groups', 'inn')]])->validate();
            $group = DeviceGroup::create(['name' => $payload['name'], 'note' => $payload['note'], 'inn' => $payload['inn'] ?? null]);
            $group->syncAccessorUserGroups($payload['user_group_ids']);
            ConsoleAudit::record('group.create', 'Created device group '.$group->name, 'group', (string) $group->id);

            return ['organization' => ['id' => (int) $group->id, 'name' => $group->name], 'user_group_ids' => $payload['user_group_ids']];
        });

        return response()->json($result);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['group_id' => 'required|integer|min:1', 'request_id' => 'required|uuid']);
        ClientOrganizations::device($request->user(), $id);
        abort_unless(ClientOrganizations::permissions($request->user())['can_attach'], 403);
        $payload = ['device_id' => $id, 'group_id' => (int) $data['group_id']];
        $result = ClientOrganizations::once($request->user(), 'attach', $data['request_id'], $payload, function () use ($request, $id, $payload) {
            $device = ClientOrganizations::device($request->user(), $id, true);
            $group = ClientOrganizations::attach($request->user(), $device, $payload['group_id']);
            ConsoleAudit::record('device.update', 'Attached organization '.$group->name.' to '.$id, 'device', $id);

            return ['organization' => ['id' => (int) $group->id, 'name' => $group->name]];
        });
        // Return current bindings, not the cached snapshot of a previous request.
        $device = ClientOrganizations::device($request->user(), $id);

        return response()->json($result + ['bindings' => ClientOrganizations::bindings($device)]);
    }
}
