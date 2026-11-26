<?php

use App\Livewire\SecurityOverview;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('restricts the security page and Livewire component to super administrators', function () {
    $user = User::create(['username' => 'security-reader', 'password' => 'test']);
    $this->actingAs($user)->get('/security')->assertRedirect(route('overview'));
    Livewire::test(SecurityOverview::class)->assertForbidden();
});

it('authenticates and validates monitor reports without exposing commands', function () {
    config(['cortendesk.server_secret' => 'monitor-secret']);
    $payload = ['state' => 'active', 'bans' => [['ip' => '2001:db8::1', 'until' => null]], 'events' => [['ip' => '8.8.8.8', 'action' => 'ban', 'at' => now()->timestamp]]];
    $this->postJson('/api/server/security/status', $payload)->assertUnauthorized();
    $this->withToken('monitor-secret')->postJson('/api/server/security/status', $payload)->assertNoContent();
    expect(Cache::get('statusdesk:security:fail2ban')['received_at'])->toBe(now()->timestamp);
    $bad = $payload;
    $bad['bans'][0]['ip'] = '<script>alert(1)</script>';
    $this->postJson('/api/server/security/status', $bad)->assertUnprocessable();
    expect(Cache::get('statusdesk:security:fail2ban')['bans'][0]['ip'])->toBe('2001:db8::1');
});

it('distinguishes missing, live and stale snapshots and rechecks access on updates', function () {
    $admin = User::create(['username' => 'security-admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($admin);
    $this->get('/security')->assertOk()->assertSee(__('security.title'));
    Livewire::test(SecurityOverview::class)->assertSee(__('security.no_data'));
    Cache::put('statusdesk:security:fail2ban', ['state' => 'active', 'received_at' => now()->timestamp, 'bans' => [['ip' => '8.8.8.8', 'until' => null]], 'events' => []]);
    $page = Livewire::test(SecurityOverview::class)->assertSee('8.8.8.8')->assertViewHas('fresh', true);
    $this->travel(2)->minutes();
    Livewire::test(SecurityOverview::class)->assertSee(__('security.stale'))->assertViewHas('fresh', false);
    $admin->update(['is_admin' => false]);
    $page->call('$refresh')->assertForbidden();
});

it('ships matching security translations for every supported locale', function () {
    $keys = array_keys(require base_path('lang/en/security.php'));
    foreach (array_keys(User::LOCALES) as $locale) {
        $values = require base_path('lang/'.$locale.'/security.php');
        expect(array_keys($values))->toBe($keys);
        foreach ($values as $value) {
            expect(trim($value))->not->toBe('');
        }
    }
});
