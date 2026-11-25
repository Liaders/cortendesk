<?php

use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

it('returns original nullable UTC registration dates on every visible peers page without expanding access', function () {
    $role = Role::create(['name' => 'Operator', 'permissions' => ['device' => 'rw', 'group' => 'rw']]);
    $user = User::create(['username' => 'operator', 'password' => 'test', 'role_id' => $role->id, 'is_active' => true]);
    $token = ClientToken::issue($user, ['id' => 'source']);
    $make = fn ($id, $owner) => Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => $id, 'uuid' => $id, 'status' => 'active', 'user_id' => $owner,
    ]));
    $first = $make('000001', $user->id);
    $first->forceFill(['created_at' => '2026-11-01 09:15:00'])->saveQuietly();
    $unknown = $make('000002', $user->id);
    $unknown->forceFill(['created_at' => null])->saveQuietly();
    $make('000003', null);
    $client = function () use ($token) {
        Auth::forgetGuards();

        return test()->withToken($token->token);
    };
    $client()->getJson('/api/peers?current=1&pageSize=1')->assertOk()->assertJsonPath('total', 2)
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', '000001')
        ->assertJsonPath('data.0.created_at', '2026-11-01T09:15:00Z');
    $client()->getJson('/api/peers?current=2&pageSize=1')->assertOk()
        ->assertJsonPath('data.0.id', '000002')->assertJsonPath('data.0.created_at', null);
    $client()->getJson('/api/peers?current=3&pageSize=1')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson('/api/heartbeat', ['id' => '000001', 'uuid' => '000001'])->assertOk();
    $client()->putJson('/api/statusdesk/devices/000001/alias', ['alias' => 'Updated alias'])->assertOk();
    $group = DeviceGroup::create(['name' => 'Customer']);
    $user->deviceGroups()->attach($group);
    $client()->postJson('/api/statusdesk/devices/000001/work-groups', ['group_id' => $group->id, 'request_id' => (string) Str::uuid()])->assertOk();
    expect($first->fresh()->device_group_id)->toBe($group->id);
    $client()->getJson('/api/peers?current=1&pageSize=1')->assertOk()
        ->assertJsonPath('data.0.created_at', '2026-11-01T09:15:00Z');
});
