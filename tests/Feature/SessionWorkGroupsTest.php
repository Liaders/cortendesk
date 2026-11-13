<?php

use App\Livewire\ConnectionReport;
use App\Livewire\DeviceList;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\SessionWorkGroups;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    $this->admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true, 'is_active' => true]);
    $this->mainGroup = DeviceGroup::create(['name' => 'Accounting office']);
    $this->customer = DeviceGroup::create(['name' => 'Customer A']);
    $this->target = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'target', 'uuid' => 'target', 'status' => 'active', 'device_group_id' => $this->mainGroup->id]));
    $this->token = ClientToken::issue($this->admin, ['id' => 'source']);
});

function workClient(ClientToken $token)
{
    Auth::forgetGuards();

    return test()->withToken($token->token);
}

function workSession(array $attributes = []): AuditConnection
{
    $row = new AuditConnection($attributes + [
        'action' => 'authorized', 'rustdesk_id' => 'target', 'from_peer' => 'source',
        'conn_id' => 1, 'session_id' => '123', 'created_at' => now()->subHour(), 'closed_at' => now(),
    ]);
    $row->created_at = now()->subHour();
    SessionWorkGroups::snapshot($row);
    $row->save();

    return $row;
}

it('edits additional groups while retaining the main group and device access rules', function () {
    $this->actingAs($this->admin);
    Livewire::test(DeviceList::class)->call('edit', $this->target->id)
        ->set('formWorkGroupIds', [$this->mainGroup->id, $this->customer->id])->call('save')->assertHasNoErrors();
    expect($this->target->fresh()->device_group_id)->toBe($this->mainGroup->id);
    expect($this->target->fresh()->workGroups->pluck('id')->all())->toBe([$this->customer->id]);
    $viewer = User::create(['username' => 'viewer', 'password' => 'test']);
    $viewer->deviceGroups()->attach($this->customer);
    expect(Device::visibleTo($viewer)->whereKey($this->target->id)->exists())->toBeFalse();
    $this->target->update(['user_id' => $viewer->id]);
    $role = Role::create(['name' => 'Device manager', 'permissions' => ['device' => 'rw']]);
    $viewer->update(['role_id' => $role->id]);
    $hidden = DeviceGroup::create(['name' => 'Hidden group']);
    $this->actingAs($viewer->fresh());
    Livewire::test(DeviceList::class)->call('edit', $this->target->id)
        ->set('formWorkGroupIds', [$hidden->id])->call('save')->assertHasErrors('formWorkGroupIds.0');
    expect($this->target->fresh()->workGroups->pluck('id')->all())->toBe([$this->customer->id]);
});

it('snapshots automatic attribution and keeps it after reassignment or deletion', function () {
    $row = workSession();
    expect($row->work_group_status)->toBe('automatic');
    expect($row->work_group_id)->toBe($this->mainGroup->id);
    $this->mainGroup->update(['name' => 'Renamed']);
    $this->target->update(['device_group_id' => $this->customer->id]);
    $row->update(['action' => 'close']);
    expect($row->fresh()->work_group_name)->toBe('Accounting office');
    $this->mainGroup->delete();
    expect($row->fresh()->work_group_name)->toBe('Accounting office');
});

it('resets additional work groups between editing and creating devices', function () {
    $this->target->workGroups()->attach($this->customer);
    $this->actingAs($this->admin);
    Livewire::test(DeviceList::class)->call('edit', $this->target->id)
        ->assertSet('formWorkGroupIds', [$this->customer->id])->call('closeModal')->call('create')
        ->assertSet('formWorkGroupIds', [])->set('formRustdeskId', 'new-target')
        ->set('formGroupId', $this->mainGroup->id)->set('formWorkGroupIds', [$this->customer->id])->call('save')->assertHasNoErrors();
    expect(Device::where('rustdesk_id', 'new-target')->firstOrFail()->workGroups->pluck('id')->all())->toBe([$this->customer->id]);
});

it('waits for login and leaves sessions from old clients explicitly unassigned', function () {
    $this->target->workGroups()->attach($this->customer);
    $this->postJson('/api/audit/conn', ['action' => 'new', 'id' => 'target', 'conn_id' => 77])->assertOk();
    $row = AuditConnection::firstOrFail();
    expect($row->work_group_status)->toBeNull();
    $payload = ['id' => 'target', 'conn_id' => 77, 'peer' => ['source', 'Operator'], 'type' => 0, 'session_id' => '18446744073709551614'];
    $this->postJson('/api/audit/conn', $payload)->assertOk();
    expect($row->fresh()->work_group_status)->toBe('required');
    expect($row->fresh()->work_group_id)->toBeNull();
    expect($row->fresh()->session_id)->toBe('18446744073709551614');
    $this->postJson('/api/audit/conn', ['action' => 'close', 'id' => 'target', 'conn_id' => 77])->assertOk();
    expect($row->fresh()->work_group_status)->toBe('required');
});

it('selects only an allowed group once and accepts retries without renaming history', function () {
    $this->target->workGroups()->attach($this->customer);
    $row = workSession();
    workClient($this->token)->getJson('/api/statusdesk/devices/target/work-groups')
        ->assertOk()->assertJsonPath('selection_required', true)->assertJsonCount(2, 'groups');
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    workClient($this->token)->putJson($url, ['group_id' => 999999])->assertStatus(422);
    workClient($this->token)->putJson($url, ['group_id' => $this->customer->id])->assertOk();
    $this->customer->update(['name' => 'Renamed customer']);
    $this->target->workGroups()->detach();
    workClient($this->token)->putJson($url, ['group_id' => $this->customer->id])->assertOk();
    workClient($this->token)->putJson($url, ['group_id' => $this->mainGroup->id])->assertStatus(409);
    expect($row->fresh()->work_group_name)->toBe('Customer A');
    expect($row->fresh()->work_group_status)->toBe('selected');
    workClient($this->token)->getJson($url)->assertOk()->assertJsonPath('status', 'selected')
        ->assertJsonPath('group.name', 'Customer A')->assertJsonCount(0, 'groups');
});

it('records authorized audits without a preceding attempt and preserves attribution on retries', function () {
    $payload = ['id' => 'target', 'conn_id' => 88, 'peer' => ['source', 'Operator'], 'type' => 0, 'session_id' => '456'];
    $this->postJson('/api/audit/conn', $payload)->assertOk();
    $row = AuditConnection::firstOrFail();
    expect($row->work_group_name)->toBe('Accounting office');
    $this->mainGroup->update(['name' => 'Renamed']);
    $this->target->workGroups()->attach($this->customer);
    $this->postJson('/api/audit/conn', $payload)->assertOk();
    expect(AuditConnection::count())->toBe(1);
    expect($row->fresh()->work_group_status)->toBe('automatic');
    expect($row->fresh()->work_group_name)->toBe('Accounting office');
});

it('requires a valid token, visible target and the exact initiating device', function () {
    $this->target->workGroups()->attach($this->customer);
    $row = workSession();
    $url = '/api/statusdesk/sessions/'.$row->guid.'/work-group';
    $this->getJson('/api/statusdesk/devices/target/work-groups')->assertUnauthorized();
    $other = ClientToken::issue($this->admin, ['id' => 'other-device']);
    workClient($other)->putJson($url, ['group_id' => $this->customer->id])->assertNotFound();
    $viewer = User::create(['username' => 'viewer', 'password' => 'test', 'is_active' => true]);
    $hiddenToken = ClientToken::issue($viewer, ['id' => 'source']);
    workClient($hiddenToken)->getJson('/api/statusdesk/devices/target/work-groups')->assertNotFound();
    workClient($hiddenToken)->putJson($url, ['group_id' => $this->customer->id])->assertNotFound();
    expect($row->fresh()->work_group_id)->toBeNull();
});

it('filters and exports recorded work groups separately from physical device groups', function () {
    $automatic = workSession();
    $this->target->workGroups()->attach($this->customer);
    $selected = workSession(['session_id' => '456']);
    workClient($this->token)->putJson('/api/statusdesk/sessions/'.$selected->guid.'/work-group', ['group_id' => $this->customer->id])->assertOk();
    workSession(['session_id' => '789']);
    $this->actingAs($this->admin);
    Livewire::test(ConnectionReport::class)->assertViewHas('count', 3)
        ->set('target.device_group_id', (string) $this->mainGroup->id)->assertViewHas('count', 3)
        ->set('workGroup', (string) $this->customer->id)->assertViewHas('count', 1)
        ->assertViewHas('total', 3600)
        ->assertViewHas('breakdown', fn ($rows) => array_values($rows)[0]['name'] === 'Customer A')
        ->set('workGroup', 'unassigned')->assertViewHas('count', 1);
    $report = new ConnectionReport;
    $report->mount();
    $report->workGroup = 'unassigned';
    app()->setLocale('ru');
    ob_start();
    $report->export()->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain('Work group', 'Organization not selected');
    expect($csv)->not->toContain('Customer A');
    ob_start();
    $report->exportXlsx()->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'work-groups-xlsx-');
    $zip = new ZipArchive;
    try {
        file_put_contents($path, $bytes);
        expect($zip->open($path))->toBeTrue();
        expect($zip->getFromName('xl/sharedStrings.xml'))->toContain('Work group', 'Organization not selected');
    } finally {
        $zip->close();
        unlink($path);
    }
});

it('keeps legacy rows on their existing report path and translates every new label', function () {
    DB::table('audit_connections')->insert(['rustdesk_id' => 'target', 'from_peer' => 'source', 'created_at' => now()->subHour(), 'closed_at' => now()]);
    $this->actingAs($this->admin);
    Livewire::test(ConnectionReport::class)->set('workGroup', (string) $this->mainGroup->id)->assertViewHas('count', 1);
    expect(AuditConnection::first()->work_group_status)->toBeNull();
    $english = require base_path('lang/en/work_groups.php');
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/work_groups.php');
        expect(array_keys($messages))->toBe(array_keys($english));
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
    }
});
