<?php

use App\Livewire\DeviceList;
use App\Livewire\RoleList;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\SessionWorkGroups;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->role = Role::create(['name' => 'Модератор', 'permissions' => ['device' => 'rw', 'audit' => 'r']]);
    $this->actor = User::create(['username' => 'moderator', 'password' => 'test', 'is_active' => true, 'role_id' => $this->role->id]);
    $this->target = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '000123', 'uuid' => 'unorganized-target', 'status' => 'active']));
    $this->allowed = DeviceGroup::create(['name' => 'Allowed organization']);
    $this->foreign = DeviceGroup::create(['name' => 'Foreign organization']);
    $this->actor->deviceGroups()->attach($this->allowed);
    $this->token = ClientToken::issue($this->actor, ['id' => 'moderator-source']);
    $this->session = new AuditConnection(['rustdesk_id' => '000123', 'from_peer' => 'moderator-source', 'action' => 'authorized', 'session_id' => '123', 'conn_id' => 1]);
    SessionWorkGroups::snapshot($this->session);
    $this->session->save();
});

function unorganizedClient()
{
    Auth::forgetGuards();

    return test()->withToken(test()->token->token);
}

function enableUnorganizedPermission(string $deviceLevel = 'rw'): void
{
    test()->role->update(['permissions' => ['device' => $deviceLevel, 'audit' => 'r', 'device_unassigned' => 'r']]);
}

it('keeps old roles and standard users restricted and reproduces the three 404 responses', function () {
    foreach (['/api/statusdesk/devices/000123/work-groups', '/api/statusdesk/devices/000123/alias', '/api/statusdesk/sessions/'.$this->session->guid.'/work-group'] as $path) {
        unorganizedClient()->getJson($path)->assertNotFound();
    }
    expect(Role::normalizePermissions([])['device_unassigned'])->toBe('none');
    $standard = User::create(['username' => 'standard', 'password' => 'test']);
    expect(Device::visibleTo($standard)->whereKey($this->target->id)->exists())->toBeFalse();
    $this->target->update(['device_group_id' => $this->allowed->id]);
    foreach (['/api/statusdesk/devices/000123/work-groups', '/api/statusdesk/devices/000123/alias', '/api/statusdesk/sessions/'.$this->session->guid.'/work-group'] as $path) {
        unorganizedClient()->getJson($path)->assertOk();
    }
});

it('opens unorganized devices through every client read path without exposing foreign memberships', function () {
    enableUnorganizedPermission();
    $foreign = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'foreign', 'uuid' => 'foreign', 'status' => 'active', 'device_group_id' => $this->foreign->id]));
    $additional = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'additional-only', 'uuid' => 'additional-only', 'status' => 'active']));
    $additional->workGroups()->attach($this->foreign);
    unorganizedClient()->getJson('/api/peers')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', '000123');
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/work-groups')->assertOk()->assertJsonCount(0, 'groups');
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertOk()->assertJsonPath('can_set_alias', true);
    unorganizedClient()->getJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group')->assertOk()->assertJsonPath('status', 'ungrouped')->assertJsonPath('permissions.can_attach_and_assign', true);
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/organization-options')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->allowed->id);
    unorganizedClient()->getJson('/api/statusdesk/organization-create-options?device_id=000123')->assertOk()->assertJsonPath('can_create', false);
    unorganizedClient()->getJson('/api/statusdesk/organizations')->assertOk()->assertJsonCount(0, 'data');
    foreach ([$foreign, $additional] as $hidden) {
        foreach (['work-groups', 'alias', 'organization-options'] as $endpoint) {
            unorganizedClient()->getJson('/api/statusdesk/devices/'.$hidden->rustdesk_id.'/'.$endpoint)->assertNotFound();
        }
        unorganizedClient()->putJson('/api/statusdesk/devices/'.$hidden->rustdesk_id.'/alias', ['alias' => 'Forbidden'])->assertNotFound();
        unorganizedClient()->postJson('/api/statusdesk/devices/'.$hidden->rustdesk_id.'/work-groups', ['group_id' => $this->allowed->id, 'request_id' => (string) Str::uuid()])->assertNotFound();
    }
    $this->actingAs($this->actor->fresh());
    Livewire::test(DeviceList::class)->assertViewHas('devices', fn ($rows) => $rows->total() === 1);
});

it('still hides pending and deleted devices and retains ordinary ownership grants', function () {
    enableUnorganizedPermission();
    $this->target->update(['status' => 'pending']);
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    $this->target->update(['status' => 'active']);
    $this->target->delete();
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/work-groups')->assertNotFound();
    $this->target->restore();
    $this->target->update(['device_group_id' => $this->foreign->id]);
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    $this->target->update(['user_id' => $this->actor->id]);
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertOk();
});

it('requires device management for writes and allowed organizations for attachment', function () {
    enableUnorganizedPermission('r');
    unorganizedClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'No'])->assertForbidden();
    unorganizedClient()->postJson('/api/statusdesk/devices/000123/work-groups', ['group_id' => $this->allowed->id, 'request_id' => (string) Str::uuid()])->assertForbidden();
    unorganizedClient()->putJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group', ['group_id' => $this->allowed->id, 'attach_device_if_needed' => true])->assertForbidden();
    enableUnorganizedPermission();
    unorganizedClient()->postJson('/api/statusdesk/devices/000123/work-groups', ['group_id' => $this->foreign->id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    unorganizedClient()->putJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group', ['group_id' => $this->foreign->id, 'attach_device_if_needed' => true])->assertUnprocessable();
    expect($this->target->fresh()->device_group_id)->toBeNull();
    expect($this->session->fresh()->work_group_status)->toBe('ungrouped');
});

it('assigns the first organization atomically and retains immutable session attribution', function () {
    enableUnorganizedPermission();
    unorganizedClient()->putJson('/api/statusdesk/devices/000123/alias', ['alias' => 'Customer workstation'])->assertOk();
    $choice = ['group_id' => $this->allowed->id, 'attach_device_if_needed' => true];
    unorganizedClient()->putJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group', $choice)->assertOk();
    expect($this->target->fresh()->device_group_id)->toBe($this->allowed->id);
    expect($this->session->fresh()->work_group_id)->toBe($this->allowed->id);
    unorganizedClient()->putJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group', $choice)->assertOk();
    unorganizedClient()->putJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group', ['group_id' => $this->foreign->id, 'attach_device_if_needed' => true])->assertConflict();
    $this->session->update(['from_peer' => 'another-source']);
    unorganizedClient()->getJson('/api/statusdesk/sessions/'.$this->session->guid.'/work-group')->assertNotFound();
});

it('rechecks permission revocation and organization changes on existing bearer tokens', function () {
    enableUnorganizedPermission();
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertOk();
    $this->role->update(['permissions' => ['device' => 'rw']]);
    unorganizedClient()->getJson('/api/statusdesk/devices/000123/alias')->assertNotFound();
    enableUnorganizedPermission();
    unorganizedClient()->postJson('/api/statusdesk/devices/000123/work-groups', ['group_id' => $this->allowed->id, 'request_id' => (string) Str::uuid()])->assertOk();
    $this->target->update(['device_group_id' => $this->foreign->id]);
    foreach (['/api/statusdesk/devices/000123/work-groups', '/api/statusdesk/devices/000123/alias', '/api/statusdesk/devices/000123/organization-options', '/api/statusdesk/sessions/'.$this->session->guid.'/work-group'] as $path) {
        unorganizedClient()->getJson($path)->assertNotFound();
    }
});

it('allows only administrators to change the separate role switch and preserves other rights', function () {
    $admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($admin);
    Livewire::test(RoleList::class)->call('edit', $this->role->id)->assertSet('accessUnorganizedDevices', false)
        ->set('accessUnorganizedDevices', true)->call('save')->assertHasNoErrors();
    expect($this->role->fresh()->permissions)->toMatchArray(['device' => 'rw', 'audit' => 'r', 'device_unassigned' => 'r']);
    Livewire::test(RoleList::class)->call('edit', $this->role->id)->assertSet('accessUnorganizedDevices', true)
        ->set('accessUnorganizedDevices', false)->call('save')->assertHasNoErrors();
    expect($this->role->fresh()->permissions['device_unassigned'])->toBe('none');
    $this->actingAs($this->actor->fresh());
    Livewire::test(RoleList::class)->assertForbidden();
});

it('localizes the permission switch in every supported language without changing token scopes', function () {
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/unorganized_devices.php');
        expect(array_keys($messages))->toBe(['label', 'hint', 'scope', 'device_hint']);
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
    }
    expect(Permissions::LEGACY_USER)->not->toHaveKey('device_unassigned');
    expect(ApiToken::RESOURCES)->not->toContain('device_unassigned');
});
