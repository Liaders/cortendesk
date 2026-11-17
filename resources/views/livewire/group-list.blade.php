<div>
    <div class="card">

            {{-- Tabs --}}
            <ul class="nav nav-tabs nav-bordered rd-tabbar">
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'devices' ? 'active' : '' }}"
                       wire:click="setTab('devices')">
                        <i class="ri-computer-line me-1"></i>{{ __('groups.device_groups') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $organizationsTotal }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'users' ? 'active' : '' }}"
                       wire:click="setTab('users')">
                        <i class="ri-group-line me-1"></i>{{ __('groups.user_groups') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $userGroupsTotal }}</span>
                    </a>
                </li>
            </ul>

            @php
                $current = $tab === 'users' ? $userGroups : $deviceGroups;
                $countLabel = $tab === 'users' ? __('common.users') : __('common.devices');
                $deleteConfirm = $tab === 'users'
                    ? __('groups.delete_this_user_group_users')
                    : __('groups.delete_this_device_group_devices');
            @endphp

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div>
                    <h4 class="header-title">{{ $tab === 'users' ? __('groups.user_groups') : __('groups.device_groups') }}</h4>
                    <p class="rd-card-sub mb-0">
                        {{ $tab === 'users'
                            ? __('groups.groups_of_people_membership_drives')
                            : __('groups.folders_of_machines_device_belongs') }}
                    </p>
                </div>
                <div class="rd-toolbar-actions">
                    @if ($tab === 'devices' && auth()->user()?->consoleAllows('group', 'rw'))
                        <button type="button" class="btn btn-outline-primary" wire:click="openImport"><i class="ri-upload-line"></i>{{ __('organization_import.import') }}</button>
                    @endif
                    <button type="button" class="btn btn-primary" wire:click="create('{{ $tab }}')">
                        <i class="ri-add-line"></i>{{ $tab === 'users' ? __('groups.add_group') : __('groups.add_device_group') }}
                    </button>
                </div>
            </div>

            <div class="row g-2 px-3 pb-3">
                <div class="col-md-8"><label class="visually-hidden" for="organization-search">{{ $tab === 'devices' ? __('organization_import.search') : __('organization_import.search_name') }}</label><input id="organization-search" type="search" maxlength="255" class="form-control" placeholder="{{ $tab === 'devices' ? __('organization_import.search') : __('organization_import.search_name') }}" wire:model.live.debounce.350ms="search"></div>
                <div class="col-md-4"><label class="visually-hidden" for="organization-per-page">{{ __('organization_import.per_page') }}</label><select id="organization-per-page" class="form-select" wire:model.live="perPage">@foreach ([25,50,100] as $size)<option value="{{ $size }}">{{ __('organization_import.page_size', ['count' => $size]) }}</option>@endforeach</select></div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.name') }}</th>
                        @if ($tab === 'devices')<th>{{ __('organization_import.inn') }}</th>@endif
                        <th>{{ __('common.note') }}</th>
                        <th>{{ $countLabel }}</th>
                        @if ($tab === 'users')
                            <th>{{ __('common.device_access') }}</th>
                        @endif
                        <th>{{ __('common.created') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($current as $group)
                        <tr wire:key="g-{{ $tab }}-{{ $group->id }}">
                            <td class="fw-semibold">@if ($tab === 'users' || ! auth()->user()?->consoleAllows('device', 'r')){{ $group->name }}@else<button type="button" class="btn btn-link p-0 text-start text-decoration-none" wire:click="showOrganizationDevices({{ $group->id }})">{{ $group->name }}</button>@endif</td>
                            @if ($tab === 'devices')<td class="text-nowrap">{{ $group->inn ?: '—' }}</td>@endif
                            <td>{{ $group->note ?: '—' }}</td>
                            <td>
                                @if ($tab === 'users')
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $group->users_count }}</span>
                                @else
                                    @include('livewire.partials.organization-device-counts')
                                @endif
                            </td>
                            @if ($tab === 'users')
                                <td>
                                    @forelse ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                            @endif
                            <td>
                                <span title="{{ $group->created_at }}">{{ $group->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end rd-rowact">
                                <a href="javascript:void(0);" class="rd-act me-2"
                                   wire:click="edit('{{ $tab }}', {{ $group->id }})">{{ __('common.edit') }}</a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                   wire:confirm="{{ $deleteConfirm }}">{{ __('common.delete') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="{{ $tab === 'users' ? 'ri-group-line' : 'ri-folders-line' }}"></i></div>
                                    <p class="rd-empty-title">
                                        {{ $tab === 'users' ? __('groups.no_user_groups_yet_click') : __('groups.no_device_groups_yet_click') }}
                                    </p>
                                    <p class="rd-empty-text">
                                        {{ $tab === 'users'
                                            ? __('groups.user_groups_decide_who_can')
                                            : __('groups.device_groups_keep_machines_organised') }}
                                    </p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create('{{ $tab }}')">{{ $tab === 'users' ? __('groups.add_group') : __('groups.add_device_group') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($current as $group)
                    <div class="rd-mini" wire:key="mg-{{ $tab }}-{{ $group->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title">@if ($tab === 'users' || ! auth()->user()?->consoleAllows('device', 'r')){{ $group->name }}@else<button type="button" class="btn btn-link p-0 text-start text-decoration-none" wire:click="showOrganizationDevices({{ $group->id }})">{{ $group->name }}</button>@endif</span>
                                    <span class="rd-mini-sub">{{ $group->note ?: __('groups.no_note') }}</span>
                                    @if ($tab === 'devices')<span class="rd-mini-sub">{{ __('organization_import.inn') }}: {{ $group->inn ?: '—' }}</span>@endif
                                </div>
                                @if ($tab === 'users')
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $group->users_count }} {{ strtolower($countLabel) }}</span>
                                @else
                                    @include('livewire.partials.organization-device-counts')
                                @endif
                            </div>
                            @if ($tab === 'users' && $group->deviceGroups->isNotEmpty())
                                <div class="mt-2">
                                    @foreach ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub">{{ __('groups.created_date', ['date' => $group->created_at?->format('Y-m-d')]) }}</span>
                                <div class="rd-mini-acts">
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}"
                                       wire:click="edit('{{ $tab }}', {{ $group->id }})"><i class="ri-pencil-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                       wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                       wire:confirm="{{ $deleteConfirm }}"><i class="ri-delete-bin-line"></i></a>
                                </div>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="{{ $tab === 'users' ? 'ri-group-line' : 'ri-folders-line' }}"></i></div>
                        <p class="rd-empty-title">
                            {{ $tab === 'users' ? __('groups.no_user_groups_yet_tap') : __('groups.no_device_groups_yet_tap') }}
                        </p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create('{{ $tab }}')">{{ $tab === 'users' ? __('groups.add_group') : __('groups.add_device_group') }}</button>
                    </div>
                @endforelse
            </div>
            <div class="p-3">{{ $current->links() }}</div>
    </div>

    @if ($tab === 'devices' && $viewedOrganization)
        @include('livewire.partials.organization-devices')
    @endif

    {{-- Create / Edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @include('livewire.partials.group-edit-modal')
    @include('livewire.partials.organization-import')
</div>
