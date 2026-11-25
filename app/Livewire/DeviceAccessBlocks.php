<?php

namespace App\Livewire;

use App\Http\Controllers\Api\FirewallPolicyController;
use App\Models\AccessBlock;
use App\Models\ConsoleAudit;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DeviceAccessBlocks extends Component
{
    #[Locked]
    public int $deviceId;

    #[Locked]
    public ?string $confirmedIp = null;

    public string $reason = '';

    public function mount(int $deviceId): void
    {
        $this->deviceId = $deviceId;
        $this->confirmedIp = AccessBlock::normalizeIp($this->device()->last_online_ip);
    }

    private function device(): Device
    {
        abort_unless(auth()->user()?->is_admin, 403);

        return Device::withTrashed()->findOrFail($this->deviceId);
    }

    public function block(string $kind): void
    {
        $device = $this->device();
        if (! AccessBlock::enforcementReady()) {
            $this->addError('reason', __('access_blocks.not_ready'));

            return;
        }
        abort_unless($kind === 'ip', 422);
        $this->reason = trim($this->reason);
        $this->validate(['reason' => ['required', 'string', 'max:500']]);
        $value = $this->confirmedIp;
        if ($kind === 'ip' && (! $value || ! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            $this->addError('reason', __('access_blocks.public_ip_required'));

            return;
        }
        DB::transaction(function () use ($device, $kind, $value) {
            // Serialize actions per device and retain previous block records for auditing.
            Device::withTrashed()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (! AccessBlock::denies($kind, $value)) {
                AccessBlock::create([
                    'kind' => $kind, 'value' => $value, 'reason' => trim($this->reason),
                    'device_id' => $device->id, 'created_by' => auth()->id(),
                    'expires_at' => now()->addHours(24),
                ]);
                ConsoleAudit::record('access.block', $kind.': '.$value, 'device', (string) $device->id);
            }
        });
        $this->reason = '';
    }

    public function revoke(int $id): void
    {
        $device = $this->device();
        $block = $this->blocksFor($device)->effective()->findOrFail($id);
        DB::transaction(function () use ($block, $device) {
            $block->update(['revoked_at' => now(), 'revoked_by' => auth()->id()]);
            ConsoleAudit::record('access.unblock', $block->kind.': '.$block->value, 'device', (string) $device->id);
        });
    }

    public function render()
    {
        $device = $this->device();
        $ip = $this->confirmedIp;

        return view('livewire.device-access-blocks', [
            'device' => $device, 'ip' => $ip, 'ready' => AccessBlock::enforcementReady(),
            'applied' => AccessBlock::enforcementReady() && Cache::get('statusdesk:firewall:digest') === FirewallPolicyController::snapshot()['digest'],
            'sharedCount' => $ip ? Device::withTrashed()->whereIn('last_online_ip', [$ip, '::ffff:'.$ip])->count() : 0,
            'blocks' => $this->blocksFor($device)->with('creator')->latest('id')->limit(20)->get(),
        ]);
    }

    private function blocksFor(Device $device)
    {
        return AccessBlock::where(fn ($q) => $q->where('device_id', $device->id)
            ->orWhere(fn ($q) => $q->where('kind', 'ip')->where('value', $this->confirmedIp)));
    }
}
