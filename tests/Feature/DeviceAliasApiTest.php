<?php

use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->actor = User::create(['username' => 'alias-editor', 'password' => 'test', 'is_active' => true]);
    $this->target = Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => '000123', 'uuid' => 'alias-target', 'status' => 'active',
        'user_id' => $this->actor->id, 'hostname' => 'Original machine name', 'alias' => 'Old alias',
    ]));
    $this->token = ClientToken::issue($this->actor, ['id' => 'source']);
});

function aliasClient(?ClientToken $token = null)
{
    Auth::forgetGuards();

    return test()->withToken(($token ?? test()->token)->token);
}

it('reads and edits the server alias without changing the hostname or peer identity', function () {
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertOk()->assertExactJson([
        'api_version' => 2, 'device_id' => '000123', 'alias' => 'Old alias', 'can_set_alias' => true, 'max_length' => 255,
    ]);
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', [
        'alias' => 'Бухгалтерия', 'hostname' => 'Do not save', 'info' => ['device_name' => 'Do not save'],
    ])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('alias', 'Бухгалтерия')->assertJsonPath('device_id', '000123');
    expect($this->target->fresh()->hostname)->toBe('Original machine name');
    expect($this->target->fresh()->alias)->toBe('Бухгалтерия');
    aliasClient()->getJson('/api/peers')->assertOk()->assertJsonPath('data.0.alias', 'Бухгалтерия')
        ->assertJsonPath('data.0.info.device_name', 'Original machine name');
    expect(ConsoleAudit::where('action', 'device.update')->where('user_id', $this->actor->id)->count())->toBe(1);
});

it('clears with null or an empty string and accepts zero as a real alias', function () {
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => null])->assertOk()->assertJsonPath('alias', '');
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => '0'])->assertOk()->assertJsonPath('alias', '0');
    expect($this->target->fresh()->alias)->toBe('0');
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => ''])->assertOk()->assertJsonPath('alias', '');
});

it('validates alias presence, type and Unicode length and accepts safe retries', function () {
    $url = '/api/statusdesk/devices/000123/alias';
    aliasClient()->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('alias');
    aliasClient()->putJson($url, ['alias' => ['bad']])->assertUnprocessable();
    aliasClient()->putJson($url, ['alias' => 123])->assertUnprocessable();
    aliasClient()->putJson($url, ['alias' => str_repeat('Я', 256)])->assertUnprocessable();
    $value = str_repeat('Я', 255);
    aliasClient()->putJson($url, ['alias' => $value])->assertOk();
    aliasClient()->putJson($url, ['alias' => $value])->assertOk()->assertJsonPath('alias', $value);
    expect(ConsoleAudit::where('action', 'device.update')->count())->toBe(1);
});

it('exposes read-only capability and rechecks permissions when saving', function () {
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertJsonPath('can_set_alias', true);
    $role = Role::create(['name' => 'Alias viewer', 'permissions' => ['device' => 'r']]);
    $this->actor->update(['role_id' => $role->id]);
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertOk()->assertJsonPath('can_set_alias', false);
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertForbidden();
    expect($this->target->fresh()->alias)->toBe('Old alias');
});

it('never grants visibility through accounting groups or device-management roles', function () {
    $role = Role::create(['name' => 'Device manager', 'permissions' => ['device' => 'rw']]);
    $other = User::create(['username' => 'hidden-editor', 'password' => 'test', 'is_active' => true, 'role_id' => $role->id]);
    $group = DeviceGroup::create(['name' => 'Accounting only']);
    $other->deviceGroups()->attach($group);
    $this->target->workGroups()->attach($group);
    $token = ClientToken::issue($other, ['id' => 'other-source']);
    aliasClient($token)->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    aliasClient($token)->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertNotFound();
    expect($this->target->fresh()->alias)->toBe('Old alias');
});

it('hides pending and deleted devices even from admins', function () {
    $this->actor->update(['is_admin' => true]);
    $this->target->update(['status' => 'pending']);
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertNotFound();
    $this->target->update(['status' => 'active']);
    $this->target->delete();
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertNotFound();
});

it('requires a valid client bearer and rejects disabled accounts', function () {
    $this->getJson('/api/statusdesk/devices/000123/alias')->assertUnauthorized();
    $this->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertUnauthorized();
    $this->actor->update(['is_active' => false]);
    aliasClient()->getJson('/api/statusdesk/devices/000123/alias')->assertUnauthorized();
    aliasClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Forbidden'])->assertUnauthorized();
});
