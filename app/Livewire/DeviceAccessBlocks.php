<?php

namespace App\Livewire;

use App\Models\AccessBlock;
use App\Models\ConsoleAudit;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DeviceAccessBlocks extends Component
{
    #[Locked]
    public int $deviceId;

    public string $reason = '';

    public function mount(int $deviceId): void
    {
        $this->deviceId = $deviceId;
        $this->device();
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
        abort_unless(in_array($kind, ['device', 'ip'], true), 422);
        $this->reason = trim($this->reason);
        $this->validate(['reason' => ['required', 'string', 'max:500']]);
        $value = $kind === 'device' ? $device->rustdesk_id : AccessBlock::normalizeIp($device->last_online_ip);
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
                    'expires_at' => $kind === 'ip' ? now()->addHours(24) : null,
                ]);
                ConsoleAudit::record('access.block', $kind.': '.$value, 'device', (string) $device->id);
            }
        });
        $this->reason = '';
    }

    public function revoke(int $id): void
    {
        $device = $this->device();
        $block = AccessBlock::effective()->where('device_id', $device->id)->findOrFail($id);
        DB::transaction(function () use ($block, $device) {
            $block->update(['revoked_at' => now(), 'revoked_by' => auth()->id()]);
            ConsoleAudit::record('access.unblock', $block->kind.': '.$block->value, 'device', (string) $device->id);
        });
    }

    public function render()
    {
        $device = $this->device();
        $ip = AccessBlock::normalizeIp($device->last_online_ip);

        return view('livewire.device-access-blocks', [
            'device' => $device, 'ip' => $ip,
            'sharedCount' => $ip ? Device::withTrashed()->where('last_online_ip', $device->last_online_ip)->count() : 0,
            'blocks' => AccessBlock::where('device_id', $device->id)->latest('id')->limit(20)->get(),
        ]);
    }
}
