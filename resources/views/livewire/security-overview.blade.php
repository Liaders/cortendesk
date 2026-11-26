<div wire:poll.30s>
    <div class="card"><div class="card-body">
        <h5>{{ __('security.fail2ban') }}</h5>
        <p>{{ __('security.readonly') }}</p>
        <div class="{{ $fresh && $snapshot['state'] === 'active' ? 'text-success' : 'text-warning' }}">
            {{ !$snapshot ? __('security.no_data') : (!$fresh ? __('security.stale') : __('security.'.$snapshot['state'])) }}
        </div>
        @if ($snapshot)
            <small class="text-muted">{{ __('security.updated') }}: {{ \Carbon\CarbonImmutable::createFromTimestamp($snapshot['received_at'])->timezone('Europe/Moscow')->format('j.n.Y H:i:s') }} GMT+3</small>
        @endif
    </div></div>
    <div class="card"><div class="card-body">
        <h5>{{ __('security.bans') }}</h5>
        <p class="text-muted">{{ __('security.scope') }}</p>
        @if ($snapshot && $snapshot['state'] === 'active')
            <p class="text-muted fs-13">{{ __('security.geo_hint') }} <a href="https://ipwhois.io/" target="_blank" rel="noopener noreferrer">IPWHOIS.io</a></p>
            <div class="table-responsive"><table class="table"><thead><tr><th>IP</th><th>{{ __('security.country') }}</th><th>{{ __('security.provider') }}</th><th>{{ __('security.since') }}</th><th>{{ __('security.until') }}</th><th>{{ __('security.left') }}</th></tr></thead><tbody>
                @forelse ($snapshot['bans'] as $ban)
                    <tr><td>{{ $ban['ip'] }}
                        @if (($ban['repeated'] ?? null) === true)<br><small class="text-warning">{{ __('security.repeated') }}</small>@endif
                    </td><td>{{ \App\Support\SecurityPresentation::country($ban['country'] ?? null) }}</td>
                    <td>{{ $ban['provider'] ?? __('security.unknown') }}</td>
                    <td class="text-nowrap">{{ ($ban['since'] ?? null) ? \Carbon\CarbonImmutable::createFromTimestamp($ban['since'])->timezone('Europe/Moscow')->format('j.n.Y H:i:s') : __('security.unknown') }}</td>
                    <td class="text-nowrap">{{ $ban['until'] ? \Carbon\CarbonImmutable::createFromTimestamp($ban['until'])->timezone('Europe/Moscow')->format('j.n.Y H:i:s') : __('security.unknown') }}</td>
                    <td>{{ $ban['until'] ? \App\Support\SessionTime::format(max(0, $ban['until'] - now()->timestamp)) : __('security.unknown') }}</td></tr>
                @empty<tr><td colspan="6">{{ __('security.empty') }}</td></tr>@endforelse
            </tbody></table></div>
        @endif
    </div></div>
    <div class="card"><div class="card-body">
        <h5>{{ __('security.history') }}</h5><p class="text-muted">{{ __('security.history_hint') }}</p>
        @if ($snapshot)
            <div class="table-responsive"><table class="table"><thead><tr><th>{{ __('security.time') }}</th><th>IP</th><th>{{ __('security.event') }}</th></tr></thead><tbody>
                @forelse ($snapshot['events'] as $event)
                    <tr><td>{{ \Carbon\CarbonImmutable::createFromTimestamp($event['at'])->timezone('Europe/Moscow')->format('j.n.Y H:i:s') }}</td><td>{{ $event['ip'] }}</td><td>{{ __('security.'.$event['action']) }}</td></tr>
                @empty<tr><td colspan="3">{{ __('security.empty') }}</td></tr>@endforelse
            </tbody></table></div>
        @endif
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('security.manual') }}</h5>
        <p class="text-muted">{{ __('security.manual_hint') }}</p>
        <div class="table-responsive"><table class="table"><thead><tr><th>IP</th><th>{{ __('security.reason') }}</th><th>{{ __('security.until') }}</th><th>{{ __('security.event') }}</th></tr></thead><tbody>
            @forelse ($manual as $block)
                <tr><td>{{ $block->value }}</td><td>{{ $block->reason }}</td><td>{{ $block->expires_at?->timezone('Europe/Moscow')->format('j.n.Y H:i:s') }}</td><td>{{ !$block->revoked_at && $block->expires_at?->isFuture() ? __('security.ban') : __('security.inactive') }}</td></tr>
            @empty<tr><td colspan="4">{{ __('security.empty') }}</td></tr>@endforelse
        </tbody></table></div>
    </div></div>
</div>
