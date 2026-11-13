<details class="mb-3" @if ($formWorkGroupIds !== []) open @endif x-data="{query: ''}">
    <summary class="form-label">{{ __('work_groups.additional') }}</summary>
    <p class="form-text">{{ __('work_groups.device_help') }}</p>
    <label class="visually-hidden" for="work-group-search">{{ __('devices.search_group') }}</label>
    <input id="work-group-search" type="search" class="form-control mb-2" x-model="query" placeholder="{{ __('devices.search_group') }}">
    <div class="border rounded p-2" style="max-height: 12rem; overflow-y: auto;">
        @foreach ($editorWorkGroups as $group)
            <label class="d-block py-1" wire:key="work-group-option-{{ $group->id }}" data-name="{{ $group->name }}"
                   x-show="$el.dataset.name.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase())">
                <input class="form-check-input me-2" type="checkbox" value="{{ $group->id }}" wire:model="formWorkGroupIds"
                       :disabled="Number($wire.formGroupId) === {{ $group->id }}">
                {{ $group->name }}
            </label>
        @endforeach
    </div>
    @error('formWorkGroupIds') <div class="text-danger">{{ $message }}</div> @enderror
    @error('formWorkGroupIds.*') <div class="text-danger">{{ $message }}</div> @enderror
</details>
