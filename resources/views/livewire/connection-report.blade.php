<div>
    <div class="card"><div class="card-body">
        <div class="row g-3 mb-3">
            @include('livewire.partials.report-date', ['field' => 'dateFrom', 'id' => 'report-from', 'caption' => __('report.date_from')])
            @include('livewire.partials.report-date', ['field' => 'dateTo', 'id' => 'report-to', 'caption' => __('report.date_to')])
            <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-outline-primary" wire:click="period('this')">{{ __('report.this_month') }}</button><button class="btn btn-outline-primary" wire:click="period('last')">{{ __('report.last_month') }}</button></div>
        </div>
        <p class="text-muted fs-13" id="report-date-hint">{{ __('report.date_hint') }} · {{ \App\Livewire\ConnectionReport::TIMEZONE_LABEL }}</p>
        <div class="row g-3">
        @foreach (['source' => __('report.from'), 'target' => __('report.to')] as $side => $label)
            <fieldset class="col-md-6"><legend class="fs-5">{{ $label }}</legend><div class="row">
                @if ($side === 'target')
                    <div class="col-sm-6"><label class="form-label mt-2" for="report-work-group">{{ __('work_groups.work_group') }}</label>
                        <select id="report-work-group" class="form-select" wire:model.live="workGroup">
                            <option value="">{{ __('report.all_organizations') }}</option>
                            <option value="unassigned">{{ __('work_groups.unassigned') }}</option>
                            @foreach ($workGroups as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach
                        </select>
                        @error('workGroup')<span class="text-danger">{{ $message }}</span>@enderror
                    </div>
                @endif
                @foreach (['device_group_id' => __('report.device_group'), 'user_id' => __('report.user'), 'user_group_id' => __('report.user_group'), 'rustdesk_id' => __('report.device')] as $field => $caption)
                    @continue($side === 'target' && $field === 'device_group_id')
                    <div class="col-sm-6"><label class="form-label mt-2" for="{{ $side }}-{{ $field }}">{{ $caption }}</label>
                    <select id="{{ $side }}-{{ $field }}" class="form-select" wire:model.live="{{ $side }}.{{ $field }}">
                        <option value="">{{ $field === 'device_group_id' ? __('report.all_organizations') : __('report.all_'.(['user_id' => 'user', 'user_group_id' => 'user_group', 'rustdesk_id' => 'device'][$field])) }}</option>
                        @if ($field === 'device_group_id')
                            @foreach ($devices->pluck('group')->filter()->unique('id')->sortBy('name') as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                        @elseif ($field === 'user_id')
                            @foreach ($devices->pluck('user')->filter()->concat($webUsers)->unique('id')->sortBy(fn ($user) => trim((string) $user->name) ?: $user->username) as $user)<option value="{{ $user->id }}">{{ trim((string) $user->name) ?: $user->username }}</option>@endforeach
                        @elseif ($field === 'user_group_id')
                            @foreach ($devices->pluck('user')->filter()->concat($webUsers)->flatMap(fn ($user) => $user->groups)->unique('id')->sortBy('name') as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                        @else
                            @foreach ($side === 'source' ? $sourceDevices : $targetDevices as $device)<option value="{{ $device->rustdesk_id }}">{{ \App\Support\ConnectionReportPresentation::device($device) }}</option>@endforeach
                        @endif
                    </select></div>
                @endforeach
            </div></fieldset>
        @endforeach
        </div>
        <details class="text-muted mt-3 mb-3"><summary>{{ __('report.method') }}</summary><p class="mt-2">{{ __('report.help', ['timezone' => \App\Livewire\ConnectionReport::TIMEZONE_LABEL]) }}</p>
        <p>{{ __('report.attribution') }}</p><p>{{ __('console_usability.report_note') }}</p><p>{{ __('work_groups.report_help') }}</p></details>
        <button class="btn btn-primary" wire:click="exportXlsx" wire:loading.attr="disabled">{{ __('report.export_xlsx') }}</button>
        <p class="text-muted fs-13 mt-2 mb-0">{{ __('report.export_limit') }}</p>
    </div></div>
    <div class="card"><div class="card-body">
        <h5>{{ __('report.sheet_summary') }}</h5>
        <p class="text-muted mb-3">{{ $dateFrom }} — {{ $dateTo }} · {{ \App\Livewire\ConnectionReport::TIMEZONE_LABEL }}</p>
        <div class="row g-3">
            @foreach (['source' => __('report.from'), 'target' => __('report.to')] as $side => $label)
                <div class="col-md-6"><h6>{{ $label }}</h6><dl class="row mb-0">
                    @foreach ($filterSummary as $filter)
                        @continue($filter['label'] !== $label && ! str_starts_with($filter['label'], $label.' · '))
                        @php($caption = $filter['label'] === $label ? ($side === 'source' ? __('report.device_group') : __('work_groups.work_group')) : substr($filter['label'], strlen($label.' · ')))
                        <dt class="col-sm-5 fw-normal text-muted mb-2">{{ $caption }}</dt><dd class="col-sm-7 mb-2">{{ $filter['value'] }}</dd>
                    @endforeach
                </dl></div>
            @endforeach
        </div>
    </div></div>
    <div class="row g-3 mb-3">
        @foreach ([__('report.sessions') => $count, __('report.duration') => \App\Support\SessionTime::format($total), __('report.unique') => \App\Support\SessionTime::format($unique), __('report.active') => $active] as $label => $value)
            <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">{{ $label }}</div><div class="fs-4">{{ $value }}</div></div></div></div>
        @endforeach
    </div>
    <div class="card"><div class="card-body"><h5>{{ __('report.customers') }}</h5>
        <div class="table-responsive d-none d-md-block"><table class="table"><thead><tr><th>{{ __('work_groups.work_group') }}</th><th>{{ __('report.sessions') }}</th><th>{{ __('report.duration') }}</th></tr></thead><tbody>
        @forelse ($breakdown as $group)<tr><td>{{ $group['name'] }}</td><td>{{ $group['count'] }}</td><td>{{ \App\Support\SessionTime::format($group['seconds']) }}</td></tr>@empty<tr><td colspan="3">{{ __('report.empty') }}</td></tr>@endforelse
        </tbody></table></div>
        <div class="d-md-none">
            @forelse ($breakdown as $group)
                <div class="rd-mini mb-2"><span class="rd-mini-title">{{ $group['name'] }}</span><span class="rd-mini-sub">{{ $group['count'] }} · {{ \App\Support\SessionTime::format($group['seconds']) }}</span></div>
            @empty<p>{{ __('report.empty') }}</p>@endforelse
        </div>
    </div></div>
    <div class="card"><div class="card-body"><h5>{{ __('report.operators') }}</h5><div class="table-responsive d-none d-md-block"><table class="table"><thead><tr><th>{{ __('report.from') }}</th><th>{{ __('report.sessions') }}</th><th>{{ __('report.duration') }}</th></tr></thead><tbody>
        @forelse ($operators as $operator)<tr><td>{{ $operator['name'] }}</td><td>{{ $operator['count'] }}</td><td>{{ \App\Support\SessionTime::format($operator['seconds']) }}</td></tr>@empty<tr><td colspan="3">{{ __('report.empty') }}</td></tr>@endforelse
    </tbody></table></div>
    <div class="d-md-none">
        @forelse ($operators as $operator)
            <div class="rd-mini mb-2"><span class="rd-mini-title">{{ $operator['name'] }}</span><span class="rd-mini-sub">{{ $operator['count'] }} · {{ \App\Support\SessionTime::format($operator['seconds']) }}</span></div>
        @empty<p>{{ __('report.empty') }}</p>@endforelse
    </div></div></div>
    <div class="card"><div class="card-body"><h5>{{ __('report.sheet_sessions') }}</h5><div class="table-responsive d-none d-md-block"><table class="table align-middle"><thead><tr><th>{{ __('report.from') }}</th><th>{{ __('report.to_device') }}</th><th>{{ __('work_groups.work_group') }}</th><th>{{ __('report.start') }}</th><th>{{ __('report.end') }}</th><th>{{ __('report.seconds') }}</th><th>{{ __('report.note') }}</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr wire:key="report-{{ $row->id }}"><td>{{ \App\Support\ConnectionReportPresentation::operator($row, $devices, $webUsers) }}<br><small class="text-muted">{{ $row->from_peer }}</small></td><td>{{ \App\Support\ConnectionReportPresentation::device($devices->get($row->rustdesk_id), $row->rustdesk_id) }}</td><td>{{ \App\Services\SessionWorkGroups::label($row) }}</td><td class="text-nowrap">{{ \App\Support\ConnectionReportPresentation::dateTime($row->created_at) }}</td><td class="text-nowrap">{{ \App\Support\ConnectionReportPresentation::dateTime($row->closed_at) }}</td><td>{{ $row->closed_at ? \App\Support\SessionTime::format(max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp))) : '—' }}</td><td>{{ $row->note }}</td></tr>
        @empty<tr><td colspan="7">{{ __('report.empty') }}</td></tr>@endforelse
    </tbody></table></div>
    <div class="d-md-none">
        @forelse ($rows as $row)
            <div class="rd-mini mb-2" wire:key="report-mobile-{{ $row->id }}">
                <span class="rd-mini-title">{{ \App\Support\ConnectionReportPresentation::operator($row, $devices, $webUsers) }} → {{ \App\Support\ConnectionReportPresentation::device($devices->get($row->rustdesk_id), $row->rustdesk_id) }}</span>
                <span class="rd-mini-sub">{{ $row->from_peer }}</span>
                <span class="rd-mini-sub">{{ \App\Services\SessionWorkGroups::label($row) }}</span>
                <span class="rd-mini-sub">{{ \App\Support\ConnectionReportPresentation::dateTime($row->created_at) }} — {{ \App\Support\ConnectionReportPresentation::dateTime($row->closed_at) }}</span>
                <span class="rd-mini-sub">{{ $row->closed_at ? \App\Support\SessionTime::format(max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp))) : '—' }}</span>
                @if ($row->note)<span class="rd-mini-sub">{{ $row->note }}</span>@endif
            </div>
        @empty<p>{{ __('report.empty') }}</p>@endforelse
    </div>{{ $rows->links() }}</div></div>
</div>
