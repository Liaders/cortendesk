<?php

use App\Livewire\DeviceAccessBlocks;
use App\Models\AccessBlock;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::create(['username' => 'block-admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($this->admin);
    config(['cortendesk.server_secret' => 'test-policy-secret']);
    Cache::forever('statusdesk:firewall:applied_at', now()->toIso8601String());

    $this->device = Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => '123456789', 'uuid' => 'device-uuid', 'status' => 'active',
        'last_online_ip' => '8.8.8.8',
    ]));
});

it('requires a healthy host firewall and only allows super administrators to manage blocks', function () {
    Cache::forget('statusdesk:firewall:applied_at');
    Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])
        ->set('reason', 'Test')->call('block', 'ip')->assertHasErrors('reason');
    expect(AccessBlock::count())->toBe(0);
    $user = User::create(['username' => 'block-reader', 'password' => 'test']);
    $this->actingAs($user);
    Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])->assertForbidden();
});

it('records and revokes IP blocks without deleting the device', function () {
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])
        ->set('reason', 'Repeated abuse')->call('block', 'ip')->assertHasNoErrors();
    $block = AccessBlock::sole();
    expect($this->device->fresh()->status)->toBe('active');
    expect(ConsoleAudit::where('action', 'access.block')->count())->toBe(1);
    Cache::forget('statusdesk:firewall:applied_at');
    $component->call('revoke', $block->id)->assertHasNoErrors();
    expect($block->fresh()->revoked_by)->toBe($this->admin->id);
    expect(AccessBlock::effective()->count())->toBe(0);
});

it('expires IP blocks exactly after 24 hours and preserves administrative and policy access', function () {
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])
        ->set('reason', 'Repeated abuse')->call('block', 'ip')->assertHasNoErrors();
    $block = AccessBlock::sole();
    expect($block->expires_at->timestamp - $block->created_at->timestamp)->toBe(86400);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
    $this->postJson('/api/heartbeat', ['id' => 'unrelated'])->assertForbidden();
    $this->get('/login')->assertStatus(302);
    $this->getJson('/api/v1/devices')->assertUnauthorized();
    $this->withToken('test-policy-secret')->getJson('/api/server/policy')->assertOk();
    $this->travelTo($block->expires_at);
    $this->postJson('/api/heartbeat', ['id' => 'unrelated'])->assertOk();
    expect(AccessBlock::count())->toBe(1);
    expect(AccessBlock::effective()->count())->toBe(0);
});

it('authenticates firewall policy and rejects stale acknowledgments', function () {
    AccessBlock::create(['kind' => 'ip', 'value' => '2001:4860::1', 'expires_at' => now()->addDay(), 'reason' => 'test']);
    AccessBlock::create(['kind' => 'ip', 'value' => '8.8.4.4', 'expires_at' => now(), 'reason' => 'expired']);
    $this->getJson('/api/server/firewall/policy')->assertUnauthorized();
    $response = $this->withToken('test-policy-secret')->getJson('/api/server/firewall/policy')->assertOk();
    expect($response->json('blocks'))->toHaveKey('2001:4860::1')->not->toHaveKey('8.8.4.4');
    $this->postJson('/api/server/firewall/applied', ['digest' => str_repeat('a', 64)])->assertConflict();
    $this->postJson('/api/server/firewall/applied', ['digest' => $response->json('digest')])->assertNoContent();
    expect(AccessBlock::enforcementReady())->toBeTrue();
    expect(AccessBlock::normalizeIp('::ffff:8.8.8.8'))->toBe('8.8.8.8');
});

it('rejects missing reasons and private IPs and never extends duplicate blocks', function () {
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->call('block', 'ip')->assertHasErrors('reason');
    $this->device->update(['last_online_ip' => '127.0.0.1']);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->set('reason', 'test')->call('block', 'ip')->assertHasErrors('reason');
    expect(AccessBlock::count())->toBe(0);
    $this->device->update(['last_online_ip' => '8.8.8.8']);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->set('reason', 'test')->call('block', 'ip');
    $expiry = AccessBlock::sole()->expires_at;
    $this->travel(1)->hours();
    Cache::forever('statusdesk:firewall:applied_at', now()->toIso8601String());

    $component->set('reason', 'duplicate')->call('block', 'ip');
    expect(AccessBlock::count())->toBe(1);
    expect(AccessBlock::sole()->expires_at->timestamp)->toBe($expiry->timestamp);
});
