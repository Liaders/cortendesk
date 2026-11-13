<?php

use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->viewer = User::create(['username' => 'viewer', 'password' => 'test', 'is_active' => true]);
    $this->token = ClientToken::issue($this->viewer, ['id' => 'source']);
});

function organizationDevice(string $id, ?DeviceGroup $group = null, ?User $owner = null, string $status = 'active'): Device
{
    return Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => $id, 'uuid' => $id, 'status' => $status,
        'device_group_id' => $group?->id, 'user_id' => $owner?->id,
    ]));
}

function organizationsGet(ClientToken $token, array $query = [])
{
    Auth::forgetGuards();

    return test()->withToken($token->token)->getJson('/api/statusdesk/organizations?'.http_build_query($query));
}

it('returns primary and additional organizations once with string peer IDs', function () {
    $a = DeviceGroup::create(['name' => 'Organization A']);
    $b = DeviceGroup::create(['name' => 'Organization B']);
    DeviceGroup::create(['name' => 'Empty organization']);
    $this->viewer->deviceGroups()->attach($a);
    $shared = organizationDevice('000123', $a);
    $shared->workGroups()->attach([$a->id, $b->id]);
    organizationDevice('18446744073709551614', $a);
    organizationDevice('hidden', $b);

    organizationsGet($this->token)->assertOk()->assertExactJson([
        'total' => 2,
        'data' => [
            ['id' => $a->id, 'name' => $a->name, 'peer_ids' => ['000123', '18446744073709551614']],
            ['id' => $b->id, 'name' => $b->name, 'peer_ids' => ['000123']],
        ],
    ]);
});

it('never grants visibility through an additional organization or leaks empty names', function () {
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $hidden = DeviceGroup::create(['name' => 'Private organization']);
    $this->viewer->deviceGroups()->attach($granted);
    organizationDevice('private', $hidden)->workGroups()->attach($granted);
    organizationDevice('private-unowned')->workGroups()->attach($granted);
    organizationsGet($this->token)->assertOk()->assertExactJson(['total' => 0, 'data' => []]);
    $owned = organizationDevice('owned', null, $this->viewer);
    $owned->workGroups()->attach($hidden);
    organizationsGet($this->token)->assertOk()->assertExactJson([
        'total' => 1,
        'data' => [['id' => $hidden->id, 'name' => $hidden->name, 'peer_ids' => ['owned']]],
    ]);
    $owned->update(['user_id' => null]);
    organizationsGet($this->token)->assertOk()->assertExactJson(['total' => 0, 'data' => []]);
});

it('honors user-group grants and excludes pending and deleted devices even for admins', function () {
    $group = DeviceGroup::create(['name' => 'Customer']);
    $team = UserGroup::create(['name' => 'Team']);
    $this->viewer->groups()->attach($team);
    $team->deviceGroups()->attach($group);
    organizationDevice('visible', $group);
    organizationDevice('pending', $group, $this->viewer, 'pending')->workGroups()->attach($group);
    $deleted = organizationDevice('deleted', $group, $this->viewer);
    $deleted->workGroups()->attach($group);
    $deleted->delete();
    $empty = DeviceGroup::create(['name' => 'Only unavailable devices']);
    organizationDevice('pending-only', $empty, $this->viewer, 'pending');
    foreach ([false, true] as $admin) {
        $this->viewer->update(['is_admin' => $admin]);
        organizationsGet($this->token)->assertOk()->assertExactJson([
            'total' => 1, 'data' => [['id' => $group->id, 'name' => $group->name, 'peer_ids' => ['visible']]],
        ]);
    }
});

it('paginates organizations while returning complete peer lists for each organization', function () {
    $a = DeviceGroup::create(['name' => 'A']);
    $b = DeviceGroup::create(['name' => 'B']);
    $this->viewer->deviceGroups()->attach([$a->id, $b->id]);
    $ids = [];
    for ($i = 0; $i < 105; $i++) {
        $ids[] = sprintf('%06d', $i);
        organizationDevice(end($ids), $a)->workGroups()->attach($b);
    }
    foreach ([1 => $a, 2 => $b] as $page => $group) {
        organizationsGet($this->token, ['current' => $page, 'pageSize' => 1])->assertOk()->assertExactJson([
            'total' => 2, 'data' => [['id' => $group->id, 'name' => $group->name, 'peer_ids' => $ids]],
        ]);
    }
    organizationsGet($this->token, ['current' => 3, 'pageSize' => 1])->assertOk()->assertExactJson(['total' => 2, 'data' => []]);
});

it('requires a valid active client login and validates the new pagination parameters', function () {
    $this->getJson('/api/statusdesk/organizations')->assertUnauthorized();
    foreach ([['current' => 0], ['current' => ['bad']], ['current' => '9223372036854775807'], ['pageSize' => 0], ['pageSize' => 501]] as $query) {
        organizationsGet($this->token, $query)->assertUnprocessable();
    }
    $this->token->update(['expires_at' => now()->subMinute()]);
    organizationsGet($this->token)->assertUnauthorized();
    $this->token->update(['expires_at' => now()->addHour()]);
    $this->viewer->update(['is_active' => false]);
    organizationsGet($this->token)->assertUnauthorized();
});

it('leaves existing group and peer responses and confirmed session assignments unchanged', function () {
    $a = DeviceGroup::create(['name' => 'A']);
    $b = DeviceGroup::create(['name' => 'B']);
    $this->viewer->deviceGroups()->attach($a);
    $device = organizationDevice('123', $a);
    $device->workGroups()->attach($b);
    $this->withToken($this->token->token)->getJson('/api/peers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.device_group_name', 'A');
    Auth::forgetGuards();
    $this->withToken($this->token->token)->getJson('/api/device-group/accessible')->assertOk()->assertExactJson(['total' => 1, 'data' => [['name' => 'A']]]);
    $row = AuditConnection::create(['rustdesk_id' => '123', 'from_peer' => 'source', 'session_id' => '456']);
    $row->forceFill(['work_group_status' => 'required'])->save();
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    organizationsGet($this->token)->assertOk();
    expect($row->fresh()->work_group_status)->toBe('required');
    Auth::forgetGuards();
    $this->withToken($this->token->token)->putJson($url, ['group_id' => $b->id])->assertOk();
    organizationsGet($this->token)->assertOk();
    Auth::forgetGuards();
    $this->withToken($this->token->token)->putJson($url, ['group_id' => $a->id])->assertConflict();
    expect($row->fresh()->work_group_id)->toBe($b->id);
});
