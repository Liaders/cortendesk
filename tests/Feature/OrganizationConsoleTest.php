<?php

use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

it('creates an additional organization without changing the primary or granting access', function () {
    $role = Role::create(['name' => 'Manager', 'permissions' => ['group' => 'rw', 'device' => 'rw']]);
    $user = User::create(['username' => 'manager', 'password' => 'test', 'role_id' => $role->id]);
    $primary = DeviceGroup::create(['name' => 'Accounting office']);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => '123', 'status' => 'active', 'user_id' => $user->id, 'device_group_id' => $primary->id]));
    $before = $user->accessibleDeviceGroupIds();
    $this->actingAs($user);
    $editor = Livewire::test(DeviceList::class)->call('edit', $device->id)->set('formAlias', 'Unsaved')
        ->assertViewHas('editorGroups', fn ($groups) => $groups->contains('id', $primary->id))
        ->call('createEditorWorkGroup')->assertSet('groupCreationPurpose', 'work');
    $token = $editor->get('groupCreationToken');
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => $token])->set('name', 'New organization')->call('save');
    $created = DeviceGroup::where('name', 'New organization')->firstOrFail();
    $editor->call('finishEditorGroup', $token)->assertSet('formGroupId', $primary->id)
        ->assertSet('formWorkGroupIds', [$created->id])->assertSet('formAlias', 'Unsaved')->call('save')->assertHasNoErrors();
    expect($device->fresh()->device_group_id)->toBe($primary->id);
    expect($device->fresh()->workGroups->pluck('id')->all())->toBe([$created->id]);
    expect($user->fresh()->accessibleDeviceGroupIds())->toBe($before);
    $editor->call('edit', $device->id)->call('createEditorWorkGroup');
    $token = $editor->get('groupCreationToken');
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => $token])->call('closeModal');
    $editor->call('finishEditorGroup', $token)->assertSet('formGroupId', $primary->id)->assertSet('formWorkGroupIds', [$created->id]);
});

it('counts and lists only visible primary and additional devices without double counting', function () {
    $role = Role::create(['name' => 'Reader', 'permissions' => ['group' => 'r', 'device' => 'r']]);
    $user = User::create(['username' => 'reader', 'password' => 'test', 'role_id' => $role->id]);
    $a = DeviceGroup::create(['name' => 'Primary']);
    $b = DeviceGroup::create(['name' => 'Additional only']);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'visible', 'uuid' => 'v', 'status' => 'active', 'user_id' => $user->id, 'device_group_id' => $a->id]));
    $device->workGroups()->attach([$a->id, $b->id]);
    $hidden = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'hidden', 'uuid' => 'h', 'status' => 'active', 'device_group_id' => $b->id]));
    $hidden->workGroups()->attach($a);
    $pending = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'pending', 'uuid' => 'p', 'status' => 'pending', 'user_id' => $user->id]));
    $pending->workGroups()->attach($b);
    $this->actingAs($user);
    Livewire::test(GroupList::class)->assertViewHas('deviceGroups', function ($groups) use ($a, $b) {
        $primary = $groups->find($a->id);
        $additional = $groups->find($b->id);

        return $primary->devices_count === 1 && $primary->work_devices_count === 0
            && $additional->devices_count === 0 && $additional->work_devices_count === 1;
    })->call('showOrganizationDevices', $b->id)
        ->assertViewHas('primaryDevices', fn ($rows) => $rows->isEmpty())
        ->assertViewHas('additionalDevices', fn ($rows) => $rows->pluck('rustdesk_id')->all() === ['visible'])
        ->assertDontSee('hidden')->assertDontSee('pending');
    $role->update(['permissions' => ['group' => 'r']]);
    $this->actingAs($user->fresh());
    Livewire::test(GroupList::class)->call('showOrganizationDevices', $a->id)->assertForbidden();
});

it('denies additional organization creation without group management and translates the interface', function () {
    $role = Role::create(['name' => 'Device editor', 'permissions' => ['group' => 'r', 'device' => 'rw']]);
    $user = User::create(['username' => 'editor', 'password' => 'test', 'role_id' => $role->id]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'own', 'uuid' => 'own', 'status' => 'active', 'user_id' => $user->id]));
    $this->actingAs($user);
    Livewire::test(DeviceList::class)->call('edit', $device->id)->call('createEditorWorkGroup')->assertForbidden();
    $english = require base_path('lang/en/organizations.php');
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/organizations.php');
        expect(array_keys($messages))->toBe(array_keys($english));
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
        expect($messages['remove'])->toContain(':name');
    }
    expect(__('organizations.section', [], 'ru'))->toBe('Организации и группы');
});
