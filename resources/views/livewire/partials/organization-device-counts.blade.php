<div class="d-flex flex-column align-items-start gap-1 fs-13">
    <span>{{ __('organizations.primary_devices') }}: <strong>{{ $group->devices_count }}</strong></span>
    <span class="text-muted">{{ __('organizations.additional_devices') }}: <strong>{{ $group->work_devices_count }}</strong></span>
</div>
