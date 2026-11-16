<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClientOrganizations
{
    public static function device(User $actor, string $id, bool $lock = false): Device
    {
        return Device::visibleTo($actor)->where('rustdesk_id', $id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public static function permissions(User $actor): array
    {
        $attach = $actor->consoleAllows('device', 'rw');

        return ['can_attach' => $attach, 'can_create' => $attach && $actor->consoleAllows('group', 'rw')];
    }

    public static function bindings(Device $device): array
    {
        return SessionWorkGroups::choices($device)->map(fn ($group) => [
            'id' => (int) $group->id, 'name' => $group->name,
            'kind' => (int) $device->device_group_id === (int) $group->id ? 'primary' : 'additional',
        ])->all();
    }

    public static function attachable(User $actor, Device $device): Builder
    {
        $query = DeviceGroup::query();
        if (! self::permissions($actor)['can_attach']) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->is_admin) {
            return $query;
        }

        // Created empty groups are manageable by their creator without granting fleet access.
        $created = DB::table('client_organization_requests')->where('user_id', $actor->id)
            ->where('operation', 'create')->whereNotNull('organization_id')->pluck('organization_id')->all();
        $ids = array_merge($actor->accessibleDeviceGroupIds(), SessionWorkGroups::choices($device)->pluck('id')->all(), $created);

        return $query->whereIn('id', $ids);
    }

    public static function userGroups(User $actor)
    {
        return UserGroup::query()->when(! $actor->is_admin, fn ($query) => $query->whereIn('id', $actor->userGroupIds()))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /** The first binding is primary; subsequent bindings are accounting-only. */
    public static function attach(User $actor, Device $device, int $groupId): DeviceGroup
    {
        abort_unless(self::permissions($actor)['can_attach'], 403, 'Device management permission required.');
        $group = self::attachable($actor, $device)->whereKey($groupId)->first();
        abort_if($group === null, 422, 'Organization is not available for attachment.');
        if (SessionWorkGroups::choices($device)->contains('id', $groupId)) {
            return $group;
        }
        if (SessionWorkGroups::choices($device)->isEmpty()) {
            $device->update(['device_group_id' => $groupId]);
        } else {
            $device->workGroups()->syncWithoutDetaching([$groupId]);
        }
        $device->unsetRelation('group')->unsetRelation('workGroups');

        return $group;
    }

    /** Persist result and mutation together; retries never create another group. */
    public static function once(User $actor, string $operation, string $requestId, array $payload, Closure $mutate): array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $operation, $requestId, $hash, $mutate) {
            $key = ['user_id' => $actor->id, 'operation' => $operation, 'request_id' => strtolower($requestId)];
            DB::table('client_organization_requests')->insertOrIgnore($key + ['payload_hash' => $hash, 'created_at' => now()]);
            $receipt = DB::table('client_organization_requests')->where($key)->lockForUpdate()->first();
            abort_unless($receipt !== null && hash_equals($receipt->payload_hash, $hash), 409, 'Request ID was used with different data.');
            if ($receipt->response !== null) {
                abort_unless(DeviceGroup::whereKey($receipt->organization_id)->exists(), 409, 'Organization was deleted.');

                return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $mutate();
            DB::table('client_organization_requests')->where($key)->update([
                'organization_id' => $result['organization']['id'],
                'response' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);

            return $result;
        }, 5);
    }
}
