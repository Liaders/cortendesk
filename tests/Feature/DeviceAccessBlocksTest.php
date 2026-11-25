<?php

use App\Livewire\DeviceAccessBlocks;
use App\Models\AccessBlock;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use App\Services\ServerLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::create(['username' => 'block-admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($this->admin);
    config(['cortendesk.server_secret' => 'test-policy-secret']);
    ServerLink::recordPull('access-blocks-v1/hbbs');
    ServerLink::recordPull('access-blocks-v1/hbbr');
    $this->device = Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => '123456789', 'uuid' => 'device-uuid', 'status' => 'active',
        'last_online_ip' => '8.8.8.8',
    ]));
});

it('requires both compatible servers and only allows super administrators to manage blocks', function () {
    Cache::forget('statusdesk:access-blocks:hbbr');
    Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])
        ->set('reason', 'Test')->call('block', 'device')->assertHasErrors('reason');
    expect(AccessBlock::count())->toBe(0);
    $user = User::create(['username' => 'block-reader', 'password' => 'test']);
    $this->actingAs($user);
    Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])->assertForbidden();
});

it('blocks a device ID without deleting it, rejects old client tokens and allows revocation', function () {
    $token = ClientToken::issue($this->admin, ['id' => $this->device->rustdesk_id]);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id])
        ->set('reason', 'Unwanted device')->call('block', 'device')->assertHasNoErrors();
    $block = AccessBlock::sole();
    expect($block->expires_at)->toBeNull();
    expect($this->device->fresh()->status)->toBe('active');
    expect(ConsoleAudit::where('action', 'access.block')->count())->toBe(1);
    $this->postJson('/api/heartbeat', ['id' => $this->device->rustdesk_id, 'uuid' => 'device-uuid'])->assertForbidden()->assertJsonPath('code', 'access_blocked');
    Auth::forgetGuards();
    $this->withToken($token->token)->getJson('/api/peers')->assertForbidden();
    $this->actingAs($this->admin);
    $component->call('revoke', $block->id)->assertHasNoErrors();
    expect($block->fresh()->revoked_by)->toBe($this->admin->id);
    expect(AccessBlock::effective()->count())->toBe(0);
    expect(ConsoleAudit::where('action', 'access.unblock')->count())->toBe(1);
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

it('publishes canonical IP expiries and device deny lists without changing allow lists', function () {
    AccessBlock::create(['kind' => 'device', 'value' => $this->device->rustdesk_id, 'reason' => 'test']);
    AccessBlock::create(['kind' => 'ip', 'value' => '2001:4860::1', 'expires_at' => now()->addDay(), 'reason' => 'test']);
    AccessBlock::create(['kind' => 'ip', 'value' => '8.8.4.4', 'expires_at' => now(), 'reason' => 'expired']);
    $policy = app(ServerLink::class)->policy();
    expect($policy['blocked'])->toBe([$this->device->rustdesk_id]);
    expect((array) $policy['blocked_ips'])->toHaveKey('2001:4860::1')->not->toHaveKey('8.8.4.4');
    expect(AccessBlock::normalizeIp('::ffff:8.8.8.8'))->toBe('8.8.8.8');
    expect(AccessBlock::normalizeIp('2001:4860:0:0:0:0:0:1'))->toBe('2001:4860::1');
});

it('rejects missing reasons and private IPs and never extends duplicate blocks', function () {
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->call('block', 'device')->assertHasErrors('reason');
    $this->device->update(['last_online_ip' => '127.0.0.1']);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->set('reason', 'test')->call('block', 'ip')->assertHasErrors('reason');
    expect(AccessBlock::count())->toBe(0);
    $this->device->update(['last_online_ip' => '8.8.8.8']);
    $component = Livewire::test(DeviceAccessBlocks::class, ['deviceId' => $this->device->id]);
    $component->set('reason', 'test')->call('block', 'ip');
    $expiry = AccessBlock::sole()->expires_at;
    $this->travel(1)->hours();
    ServerLink::recordPull('access-blocks-v1/hbbs');
    ServerLink::recordPull('access-blocks-v1/hbbr');
    $component->set('reason', 'duplicate')->call('block', 'ip');
    expect(AccessBlock::count())->toBe(1);
    expect(AccessBlock::sole()->expires_at->timestamp)->toBe($expiry->timestamp);
});
