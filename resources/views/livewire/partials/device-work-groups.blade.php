@php($workPickerGroups = $editorWorkGroups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name])->values())
<div class="mb-3" x-data="rdWorkGroupPicker($wire, @js($workPickerGroups), @js((bool) auth()->user()?->consoleAllows('group', 'rw')), @js(auth()->user()?->consoleAllows('group', 'rw') ? __('organizations.create') : ''))"
     x-id="['work-input', 'work-options', 'work-label']" @click.outside="close()"
     @focusout="if (!$el.contains($event.relatedTarget)) close()">
    <label class="form-label" :id="$id('work-label')" :for="$id('work-input')">{{ __('organizations.accounting') }}</label>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <template x-if="primary">
            <span class="badge bg-secondary-subtle text-body border d-inline-flex align-items-center gap-2 text-wrap py-2 px-2">
                <span x-text="primary.name"></span><span class="badge bg-secondary text-white">{{ __('organizations.primary_badge') }}</span>
            </span>
        </template>
        <template x-for="group in selected" :key="group.id">
            <span class="badge bg-primary-subtle text-primary border d-inline-flex align-items-center gap-2 text-wrap py-2 px-2">
                <span x-text="group.name"></span>
                <button type="button" class="btn p-0 border-0 text-primary lh-1" style="min-width: 1.25rem; min-height: 1.25rem;" :aria-label="@js(__('organizations.remove')).replace(':name', group.name)" @click="remove(group.id)">×</button>
            </span>
        </template>
    </div>
    <input type="text" class="form-control" role="combobox" autocomplete="off" x-ref="input"
           :id="$id('work-input')" :aria-controls="$id('work-options')" :aria-expanded="open" aria-autocomplete="list"
           :aria-activedescendant="open && active >= 0 ? $id('work-options') + '-' + active : null"
           :value="query" placeholder="{{ __('organizations.add') }}" :disabled="pending"
           @click="show()" @input="search($event.target.value)"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter.prevent="if (open && active >= 0) choose(choices[active].id)"
           @keydown.escape="if (open) { $event.stopPropagation(); close(); }" @keydown.tab="close()">
    <div x-show="open" style="display: none; max-height: 15rem; overflow-y: auto;" class="border rounded bg-body mt-1"
         role="listbox" :id="$id('work-options')" :aria-labelledby="$id('work-label')" x-ref="options">
        <template x-for="(group, index) in choices" :key="group.id">
            <button type="button" role="option" tabindex="-1" aria-selected="false"
                    class="dropdown-item text-wrap px-3 py-2" :class="{'active': active === index, 'border-top': group.id === -1}"
                    :id="$id('work-options') + '-' + index" @mousedown.prevent @mousemove="active = index" @click="choose(group.id)">
                <span x-show="group.id === -1" aria-hidden="true">＋ </span><span x-text="group.name"></span>
            </button>
        </template>
        <div x-show="matches.length === 0" class="text-muted small px-3 py-2" role="status">{{ __('devices.no_matching_groups') }}</div>
    </div>
    <div class="form-text">{{ __('organizations.accounting_help') }}</div>
    @error('formWorkGroupIds') <div class="text-danger">{{ $message }}</div> @enderror
    @error('formWorkGroupIds.*') <div class="text-danger">{{ $message }}</div> @enderror
</div>
