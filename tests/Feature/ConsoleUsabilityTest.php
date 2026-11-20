<?php

use App\Livewire\ActiveSessions;
use App\Livewire\DeviceList;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::create(['username' => 'console-admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($this->admin);
    $this->primary = DeviceGroup::create(['name' => 'Основная организация']);
    $this->extra = DeviceGroup::create(['name' => 'УЗОРМЕТАЛЛ']);
    $this->device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '000123', 'uuid' => 'console-target', 'alias' => 'Рабочее МЕСТО', 'status' => 'active', 'device_group_id' => $this->primary->id]));
    $this->device->workGroups()->attach($this->extra);
});

it('searches organization substrings and Unicode device aliases with SQL pagination', function () {
    foreach (['металл', 'узорм', 'ОСНОВНАЯ', 'рабочее место'] as $search) {
        Livewire::test(DeviceList::class)->set('search', $search)
            ->assertViewHas('devices', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->device->id);
    }
    Livewire::test(DeviceList::class)->set('search', '%')->assertViewHas('devices', fn ($rows) => $rows->total() === 0);
});

it('paginates organization filter options, preserves its label and enforces viewer scope', function () {
    for ($i = 0; $i < 65; $i++) {
        DeviceGroup::create(['name' => 'Organization '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }
    Livewire::test(DeviceList::class)->assertViewHas('filterGroups', fn ($rows) => $rows->count() === 50)
        ->call('searchFilterGroups', 'organization', 2)
        ->assertReturned(['data' => DeviceGroup::where('name', 'like', 'Organization %')->orderBy('name')->skip(50)->limit(50)->get(['id', 'name', 'inn'])->toArray(), 'has_more' => false])
        ->call('selectGroupFilter', $this->extra->id)->assertSet('group', $this->extra->id)
        ->assertSet('filterGroupLabels.'.$this->extra->id.'.name', 'УЗОРМЕТАЛЛ')
        ->call('selectGroupFilter', -1)->assertSet('group', -1);
    $role = Role::create(['name' => 'Scoped viewer', 'permissions' => ['device' => 'r']]);
    $viewer = User::create(['username' => 'viewer', 'password' => 'test', 'role_id' => $role->id]);
    $viewer->deviceGroups()->attach($this->primary);
    $this->actingAs($viewer);
    Livewire::test(DeviceList::class)->call('searchFilterGroups', 'металл')->assertReturned(['data' => [], 'has_more' => false]);
    Livewire::test(DeviceList::class)->call('selectGroupFilter', $this->extra->id)->assertForbidden();
    $this->device->update(['device_group_id' => $this->extra->id]);
    Livewire::test(DeviceList::class)->set('search', 'металл')->assertViewHas('devices', fn ($rows) => $rows->total() === 0);
});

it('shows the saved session organization on the dashboard rather than the device primary group', function () {
    app()->setLocale('ru');
    $row = new AuditConnection(['rustdesk_id' => $this->device->rustdesk_id, 'from_peer' => 'source', 'from_name' => 'Operator', 'action' => 'authorized']);
    $row->forceFill(['work_group_id' => $this->extra->id, 'work_group_name' => 'Saved old name', 'work_group_status' => 'selected'])->save();
    Livewire::test(ActiveSessions::class)->assertSee('УЗОРМЕТАЛЛ')->assertDontSee('Основная организация');
    $row->forceFill(['work_group_status' => 'required', 'work_group_id' => null])->save();
    Livewire::test(ActiveSessions::class)->assertSee(__('work_groups.unassigned'))->assertDontSee('Основная организация');
    $row->forceFill(['work_group_status' => null])->save();
    Livewire::test(ActiveSessions::class)->assertSee(__('console_usability.organization_unknown'))->assertDontSee('Основная организация');
});

it('ships every new interface label in all supported languages', function () {
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/console_usability.php');
        expect(array_keys($messages))->toBe(['search_devices', 'open_calendar', 'organization_unknown', 'report_note']);
        expect($messages['open_calendar'])->toContain(':field');
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
    }
});
