<?php

use App\Livewire\ConnectionReport;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\SessionWorkGroups;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->actor = User::create(['username' => 'manager', 'password' => 'test', 'is_active' => true]);
    $role = Role::create(['name' => 'Organization manager', 'permissions' => ['device' => 'rw', 'group' => 'rw', 'audit' => 'r']]);
    $this->actor->update(['role_id' => $role->id]);
    $this->target = Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => '000123', 'uuid' => 'test-device', 'status' => 'active', 'user_id' => $this->actor->id,
    ]));
    $this->team = UserGroup::create(['name' => 'Our team']);
    $this->actor->groups()->attach($this->team);
    $this->token = ClientToken::issue($this->actor, ['id' => 'source']);
    $this->group = DeviceGroup::create(['name' => 'Узорметалл']);
    $this->actor->deviceGroups()->attach($this->group);
});

function managementClient(?ClientToken $token = null)
{
    Auth::forgetGuards();

    return test()->withToken(($token ?? test()->token)->token);
}

function managementSession(): AuditConnection
{
    $row = new AuditConnection([
        'rustdesk_id' => '000123', 'from_peer' => 'source', 'action' => 'close',
        'conn_id' => 1, 'session_id' => '18446744073709551614', 'closed_at' => now(),
    ]);
    $row->created_at = now()->subHour();
    SessionWorkGroups::snapshot($row);
    $row->save();

    return $row;
}

it('scopes organization search, folds Cyrillic and paginates organizations', function () {
    DeviceGroup::create(['name' => 'Secret organization']);
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options?q='.urlencode('узор').'&pageSize=1')
        ->assertOk()->assertJsonPath('api_version', 2)->assertJsonPath('device_has_organization', false)
        ->assertJsonPath('permissions.can_create', true)->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $this->group->id)->assertJsonCount(0, 'bindings');
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options?current=2&pageSize=1')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options?pageSize=501')->assertUnprocessable();
});

it('offers only permitted user groups and defaults the sole own group', function () {
    UserGroup::create(['name' => 'Unrelated team']);
    managementClient()->getJson('/api/statusdesk/organization-create-options?device_id=000123')
        ->assertOk()->assertJsonPath('can_create', true)->assertJsonPath('fields.note.max_length', 500)
        ->assertJsonPath('default_user_group_ids', [$this->team->id])->assertJsonCount(1, 'user_groups');
    $second = UserGroup::create(['name' => 'Second own team']);
    $this->actor->groups()->attach($second);
    managementClient()->getJson('/api/statusdesk/organization-create-options?device_id=000123')
        ->assertOk()->assertJsonPath('default_user_group_ids', [])->assertJsonCount(2, 'user_groups');
});

it('creates once with permitted grants and detects payload conflicts', function () {
    $payload = ['device_id' => '000123', 'request_id' => (string) Str::uuid(), 'name' => 'New customer', 'user_group_ids' => [$this->team->id]];
    $response = managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk()
        ->assertJsonPath('user_group_ids', [$this->team->id]);
    $id = $response->json('organization.id');
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk()->assertJsonPath('organization.id', $id);
    managementClient()->postJson('/api/statusdesk/organizations', array_replace($payload, ['name' => 'Other customer']))->assertConflict();
    expect(DeviceGroup::where('name', 'New customer')->count())->toBe(1);
    expect(DeviceGroup::findOrFail($id)->accessorUserGroupIds())->toBe([$this->team->id]);
    expect($this->target->fresh()->device_group_id)->toBeNull();
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options?q=New')->assertJsonPath('data.0.id', $id);
});

it('rejects unauthorized grants, missing permissions and invisible devices without mutation', function () {
    $other = UserGroup::create(['name' => 'Other team']);
    $payload = ['device_id' => '000123', 'request_id' => (string) Str::uuid(), 'name' => 'Forbidden', 'user_group_ids' => [$other->id]];
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertForbidden();
    expect(DeviceGroup::where('name', 'Forbidden')->exists())->toBeFalse();
    $this->actor->update(['role_id' => null]);
    managementClient()->postJson('/api/statusdesk/organizations', array_replace($payload, ['user_group_ids' => []]))->assertForbidden();
    managementClient()->getJson('/api/statusdesk/organization-create-options?device_id=000123')
        ->assertOk()->assertJsonPath('can_create', false)->assertJsonCount(0, 'user_groups');
    $this->target->update(['user_id' => null]);
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertNotFound();
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options')->assertNotFound();
});

it('uses a primary first binding and accounting-only additional bindings', function () {
    $payload = ['group_id' => $this->group->id, 'request_id' => (string) Str::uuid()];
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', $payload)->assertOk()->assertJsonPath('bindings.0.kind', 'primary');
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', $payload)->assertOk();
    expect($this->target->fresh()->device_group_id)->toBe($this->group->id);
    $other = DeviceGroup::create(['name' => 'Another organization']);
    $this->actor->deviceGroups()->attach($other);
    $viewer = User::create(['username' => 'other-viewer', 'password' => 'test', 'is_active' => true]);
    $viewer->deviceGroups()->attach($other);
    $row = managementSession();
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', [
        'group_id' => $other->id, 'request_id' => (string) Str::uuid(),
    ])->assertOk()->assertJsonCount(2, 'bindings');
    expect($this->target->fresh()->device_group_id)->toBe($this->group->id);
    expect(Device::visibleTo($viewer)->whereKey($this->target->id)->exists())->toBeFalse();
    expect($row->fresh()->work_group_id)->toBe($this->group->id);
    expect($row->fresh()->work_group_status)->toBe('automatic');
});

it('never attaches hidden organizations or devices and respects read-only roles', function () {
    $hidden = DeviceGroup::create(['name' => 'Hidden organization']);
    $url = '/api/statusdesk/devices/000123/work-groups';
    managementClient()->postJson($url, ['group_id' => $hidden->id, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    $role = Role::create(['name' => 'Read only', 'permissions' => ['device' => 'r', 'group' => 'rw']]);
    $this->actor->update(['role_id' => $role->id]);
    managementClient()->postJson($url, ['group_id' => $this->group->id, 'request_id' => (string) Str::uuid()])->assertForbidden();
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options')
        ->assertOk()->assertJsonPath('permissions.can_attach', false)->assertJsonCount(0, 'data');
    $this->target->update(['status' => 'pending']);
    managementClient()->postJson($url, ['group_id' => $this->group->id, 'request_id' => (string) Str::uuid()])->assertNotFound();
    expect($this->target->fresh()->device_group_id)->toBeNull();
});

it('isolates request ids by actor and operation and rejects reuse for another device', function () {
    $key = (string) Str::uuid();
    $created = managementClient()->postJson('/api/statusdesk/organizations', [
        'request_id' => $key, 'device_id' => '000123', 'name' => 'Created', 'user_group_ids' => [],
    ])->assertOk()->json('organization.id');
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', ['group_id' => $created, 'request_id' => $key])->assertOk();
    $second = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'second', 'uuid' => 'second', 'status' => 'active', 'user_id' => $this->actor->id]));
    managementClient()->postJson('/api/statusdesk/devices/second/work-groups', ['group_id' => $created, 'request_id' => $key])->assertConflict();
    expect($second->fresh()->device_group_id)->toBeNull();
    $this->actor->update(['is_admin' => true]);
    $other = User::create(['username' => 'admin2', 'password' => 'test', 'is_active' => true, 'is_admin' => true]);
    $otherToken = ClientToken::issue($other, ['id' => 'source2']);
    managementClient($otherToken)->postJson('/api/statusdesk/organizations', [
        'request_id' => $key, 'device_id' => '000123', 'name' => 'Other actor', 'user_group_ids' => [],
    ])->assertOk();
    expect(DeviceGroup::whereIn('name', ['Created', 'Other actor'])->count())->toBe(2);
});

it('requires explicit opt in to assign ungrouped sessions and keeps the whole closed session', function () {
    $row = managementSession();
    expect($row->work_group_status)->toBe('ungrouped');
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    managementClient()->getJson($url)->assertOk()->assertJsonPath('permissions.can_attach_and_assign', true)
        ->assertJsonPath('device_has_organization', false)->assertJsonCount(0, 'groups');
    managementClient()->putJson($url, ['group_id' => $this->group->id])->assertConflict();
    managementClient()->putJson($url, ['group_id' => $this->group->id, 'attach_device_if_needed' => true])->assertOk()->assertJsonPath('saved', true);
    expect($row->fresh()->work_group_status)->toBe('selected');
    expect($row->fresh()->work_group_id)->toBe($this->group->id);
    expect($row->fresh()->created_at->equalTo($row->created_at))->toBeTrue();
    expect($this->target->fresh()->device_group_id)->toBe($this->group->id);
    $this->group->update(['name' => 'Renamed']);
    managementClient()->putJson($url, ['group_id' => $this->group->id, 'attach_device_if_needed' => true])->assertOk();
    managementClient()->getJson($url)->assertJsonPath('group.name', 'Узорметалл')->assertJsonPath('permissions.can_attach_and_assign', false);
    $this->actingAs($this->actor);
    Livewire::test(ConnectionReport::class)->set('workGroup', (string) $this->group->id)->assertViewHas('count', 1)->assertViewHas('total', 3600);
    $report = new ConnectionReport;
    $report->mount();
    $report->workGroup = (string) $this->group->id;
    ob_start();
    $report->export()->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain('Узорметалл')->not->toContain('Renamed');
    ob_start();
    $report->exportXlsx()->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'organization-export-');
    $zip = new ZipArchive;
    try {
        file_put_contents($path, $bytes);
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/sharedStrings.xml'))->toContain('Узорметалл')->not->toContain('Renamed');
    } finally {
        $zip->close();
        unlink($path);
    }
});

it('checks the exact initiating token and prevents a conflicting assignment from attaching another group', function () {
    $row = managementSession();
    $row->created_at = $row->closed_at->copy()->subSeconds(2);
    $row->save();
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    $payload = ['group_id' => $this->group->id, 'attach_device_if_needed' => true];
    $other = ClientToken::issue($this->actor, ['id' => 'wrong-source']);
    managementClient($other)->putJson($url, $payload)->assertNotFound();
    $noDevice = ClientToken::issue($this->actor);
    managementClient($noDevice)->putJson($url, $payload)->assertForbidden();
    managementClient()->putJson($url, $payload)->assertOk();
    expect($row->fresh()->closed_at->timestamp - $row->fresh()->created_at->timestamp)->toBe(2);
    $otherGroup = DeviceGroup::create(['name' => 'Another']);
    $this->actor->deviceGroups()->attach($otherGroup);
    managementClient()->putJson($url, ['group_id' => $otherGroup->id, 'attach_device_if_needed' => true])->assertConflict();
    expect($this->target->fresh()->workGroups)->toHaveCount(0);
});

it('does not expand old selection permissions or backfill legacy rows', function () {
    $row = managementSession();
    $role = Role::create(['name' => 'Read only', 'permissions' => ['device' => 'r']]);
    $this->actor->update(['role_id' => $role->id]);
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    managementClient()->putJson($url, ['group_id' => $this->group->id, 'attach_device_if_needed' => true])->assertForbidden();
    expect($this->target->fresh()->device_group_id)->toBeNull();
    $row->forceFill(['work_group_status' => 'required'])->save();
    $this->target->workGroups()->attach($this->group);
    managementClient()->putJson($url, ['group_id' => $this->group->id])->assertOk();
    $row->forceFill(['work_group_status' => null, 'work_group_id' => null, 'work_group_name' => null])->save();
    managementClient()->putJson($url, ['group_id' => $this->group->id, 'attach_device_if_needed' => true])->assertConflict();
});

it('rolls back a binding if persisting the session assignment fails', function () {
    $row = managementSession();
    DB::unprepared("CREATE TRIGGER reject_test_assignment BEFORE UPDATE OF work_group_status ON audit_connections WHEN NEW.work_group_status = 'selected' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    try {
        managementClient()->putJson('/api/statusdesk/sessions/'.$row->guid.'/work-group', [
            'group_id' => $this->group->id, 'attach_device_if_needed' => true,
        ])->assertStatus(500);
        expect($this->target->fresh()->device_group_id)->toBeNull();
        expect($row->fresh()->work_group_status)->toBe('ungrouped');
    } finally {
        DB::unprepared('DROP TRIGGER reject_test_assignment');
    }
});

it('requires a client token for every new endpoint', function () {
    $this->getJson('/api/statusdesk/devices/000123/organization-options')->assertUnauthorized();
    $this->getJson('/api/statusdesk/organization-create-options?device_id=000123')->assertUnauthorized();
    $this->postJson('/api/statusdesk/organizations', [])->assertUnauthorized();
    $this->postJson('/api/statusdesk/devices/000123/work-groups', [])->assertUnauthorized();
});

it('recovers creation after a lost response and assigns the created organization atomically', function () {
    $row = managementSession();
    $payload = ['device_id' => '000123', 'request_id' => (string) Str::uuid(), 'name' => 'Created during session', 'user_group_ids' => []];
    $id = managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk()->json('organization.id');
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk()->assertJsonPath('organization.id', $id);
    managementClient()->putJson('/api/statusdesk/sessions/'.$row->guid.'/work-group', [
        'group_id' => $id, 'attach_device_if_needed' => true,
    ])->assertOk();
    expect($this->target->fresh()->device_group_id)->toBe($id);
    expect($row->fresh()->work_group_name)->toBe('Created during session');
    expect(DeviceGroup::where('name', 'Created during session')->count())->toBe(1);
});

it('does not revive deleted results or restore removed bindings on replay', function () {
    $payload = ['device_id' => '000123', 'request_id' => (string) Str::uuid(), 'name' => 'Temporary', 'user_group_ids' => []];
    $id = managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk()->json('organization.id');
    $attachment = ['group_id' => $id, 'request_id' => (string) Str::uuid()];
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', $attachment)->assertOk();
    $this->target->update(['device_group_id' => null]);
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', $attachment)
        ->assertOk()->assertJsonCount(0, 'bindings');
    expect($this->target->fresh()->device_group_id)->toBeNull();
    DeviceGroup::findOrFail($id)->delete();
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertConflict();
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', $attachment)->assertConflict();
    expect(DeviceGroup::where('name', 'Temporary')->exists())->toBeFalse();
});

it('does not give an existing additional organization primary access on a later attachment', function () {
    $this->target->workGroups()->attach($this->group);
    $next = DeviceGroup::create(['name' => 'Next']);
    $this->actor->deviceGroups()->attach($next);
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', [
        'group_id' => $next->id, 'request_id' => (string) Str::uuid(),
    ])->assertOk()->assertJsonCount(2, 'bindings');
    expect($this->target->fresh()->device_group_id)->toBeNull();
    expect($this->target->fresh()->workGroups)->toHaveCount(2);
});

it('rechecks role and device access on mutation retries', function () {
    $payload = ['device_id' => '000123', 'request_id' => (string) Str::uuid(), 'name' => 'Before demotion', 'user_group_ids' => []];
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertOk();
    $this->actor->update(['role_id' => null]);
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertForbidden();
    $this->target->update(['user_id' => null]);
    managementClient()->getJson('/api/statusdesk/organization-create-options?device_id=000123')->assertNotFound();
    $this->actor->update(['is_admin' => true]);
    $this->target->delete();
    managementClient()->getJson('/api/statusdesk/devices/000123/organization-options')->assertNotFound();
    managementClient()->postJson('/api/statusdesk/organizations', $payload)->assertNotFound();
});

it('reattaches before reconnecting so a new session can choose another organization without rewriting the old session', function () {
    $this->target->update(['device_group_id' => $this->group->id]);
    $old = managementSession();
    expect($old->work_group_status)->toBe('automatic');
    $nextGroup = DeviceGroup::create(['name' => 'New session organization']);
    $this->actor->deviceGroups()->attach($nextGroup);
    // Without pre-attachment, another login would automatically confirm the old sole group.
    $premature = managementSession();
    managementClient()->putJson('/api/statusdesk/sessions/'.$premature->guid.'/work-group', [
        'group_id' => $nextGroup->id, 'attach_device_if_needed' => true,
    ])->assertConflict();
    expect($this->target->fresh()->workGroups)->toHaveCount(0);
    managementClient()->postJson('/api/statusdesk/devices/000123/work-groups', [
        'group_id' => $nextGroup->id, 'request_id' => (string) Str::uuid(),
    ])->assertOk();
    $next = managementSession();
    expect($next->guid)->not->toBe($old->guid);
    expect($next->work_group_status)->toBe('required');
    managementClient()->putJson('/api/statusdesk/sessions/'.$next->guid.'/work-group', ['group_id' => $nextGroup->id])->assertOk();
    expect($old->fresh()->work_group_id)->toBe($this->group->id);
    expect($old->fresh()->work_group_status)->toBe('automatic');
    expect($next->fresh()->work_group_id)->toBe($nextGroup->id);
});
