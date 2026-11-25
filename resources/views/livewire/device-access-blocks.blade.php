<div class="card"><div class="card-body">
    <h5>{{ __('access_blocks.title') }}</h5>
    <p class="text-muted">{{ __('access_blocks.help') }}</p>
    @unless ($ready)<p class="text-warning">{{ __('access_blocks.not_ready') }}</p>@endunless
    <p>{{ __('access_blocks.ip_warning', ['ip' => $ip ?: '—', 'count' => $sharedCount]) }}</p>
    <label for="block-reason-{{ $deviceId }}" class="form-label">{{ __('access_blocks.reason') }}</label>
    <input id="block-reason-{{ $deviceId }}" class="form-control mb-2" wire:model="reason" maxlength="500">
    @error('reason')<p class="text-danger">{{ $message }}</p>@enderror
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-danger" wire:click="block('device')" wire:confirm="{{ __('access_blocks.confirm_device') }}" wire:loading.attr="disabled">{{ __('access_blocks.block_device') }}</button>
        <button class="btn btn-outline-danger" wire:click="block('ip')" wire:confirm="{{ __('access_blocks.confirm_ip', ['ip' => $ip ?: '—']) }}" wire:loading.attr="disabled" @disabled(!$ip)>{{ __('access_blocks.block_ip') }}</button>
    </div>
    @foreach ($blocks as $block)
        <div class="border-top mt-3 pt-2" wire:key="access-block-{{ $block->id }}">
            <strong>{{ $block->kind === 'ip' ? 'IP' : __('common.device') }}: {{ $block->value }}</strong>
            <div>{{ $block->reason }}</div>
            <div class="text-muted">{{ trim((string) $block->creator?->name) ?: ($block->creator?->username ?? '—') }} · {{ $block->created_at?->timezone('Europe/Moscow')->format('j.n.Y H:i') }}</div>
            <small class="text-muted">{{ $block->created_at->timezone('Europe/Moscow')->format('j.n.Y H:i') }} · {{ $block->expires_at ? $block->expires_at->timezone('Europe/Moscow')->format('j.n.Y H:i').' GMT+3' : __('access_blocks.indefinite') }}</small>
            @if (!$block->revoked_at && (!$block->expires_at || $block->expires_at->isFuture()))
                <button class="btn btn-sm btn-outline-secondary ms-2" wire:click="revoke({{ $block->id }})" wire:confirm="{{ __('access_blocks.confirm_revoke') }}">{{ __('access_blocks.revoke') }}</button>
            @else
                <span class="text-muted">{{ __('access_blocks.inactive') }}</span>
            @endif
        </div>
    @endforeach
</div></div>
