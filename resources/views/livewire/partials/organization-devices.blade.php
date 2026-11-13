<div class="modal fade show d-block" role="dialog" aria-modal="true" aria-labelledby="organization-members-title" tabindex="-1"
     style="background: rgba(0,0,0,.6);" wire:keydown.escape="closeOrganizationDevices"
     x-data="{}" x-init="$nextTick(() => $refs.organizationClose.focus())">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="organization-members-title">{{ $viewedOrganization->name }}</h5>
            <button type="button" class="btn-close" x-ref="organizationClose" wire:click="closeOrganizationDevices" aria-label="{{ __('common.close') }}"></button>
        </div>
        <div class="modal-body">
        <p class="text-muted small">{{ __('organizations.accounting_help') }}</p>
        <div class="row g-3">
            @foreach (['primary' => $primaryDevices, 'additional' => $additionalDevices] as $kind => $members)
                <div class="col-md-6">
                    <h6>{{ $kind === 'primary' ? __('organizations.primary_devices') : __('organizations.additional_devices') }} · {{ $members->count() }}</h6>
                    <div style="max-height: 20rem; overflow-y: auto;">
                        @forelse ($members as $member)
                            <div class="border-bottom py-2" wire:key="organization-{{ $viewedOrganization->id }}-{{ $kind }}-{{ $member->id }}">
                                <a href="{{ route('devices.show', ['device' => $member->id]) }}">{{ $member->rustdesk_id }}</a>
                                @if ($member->alias)<span class="text-muted"> · {{ $member->alias }}</span>@endif
                            </div>
                        @empty
                            <p class="text-muted">{{ __('common.none') }}</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeOrganizationDevices">{{ __('common.close') }}</button></div>
    </div>
    </div>
</div>
