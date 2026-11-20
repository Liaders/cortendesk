@php($pickerGroups = $editorGroups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name, 'inn' => $group->inn])->values())
<div x-data="rdGroupPicker($wire, @js($pickerGroups), @js(__('devices.no_group')), @js(auth()->user()?->consoleAllows('group', 'rw') ? __('devices.create_new_group') : ''), @js((bool) auth()->user()?->consoleAllows('group', 'rw')))"
     x-id="['group-list', 'group-input']"
     @click.outside="close()"
     @pointerdown="pointerWithin = true" @pointerup.window="pointerWithin = false" @pointercancel.window="pointerWithin = false"
     @focusout="if (!pointerWithin && !$el.contains($event.relatedTarget)) close()">
    <label class="form-label" :for="$id('group-input')">{{ __('organizations.primary') }}</label>
    <input type="text" class="form-select" autocomplete="off" role="combobox"
           :id="$id('group-input')" :value="open ? query : label"
           :placeholder="open ? @js(__('devices.search_group')) : ''"
           :aria-expanded="open" :aria-controls="$id('group-list')" aria-autocomplete="list"
           :aria-activedescendant="open && active >= 0 ? $id('group-list') + '-' + active : null"
           :disabled="pending"
           @focus="show()" @click="show()" @input="search($event.target.value)"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter.prevent="if (open && active >= 0) choose(choices[active].id)"
           @keydown.escape="if (open) { $event.stopPropagation(); close(); }"
           @keydown.tab="close()">
    <div x-show="open" style="display: none" class="border rounded mt-1 bg-body">
        <div role="listbox" :id="$id('group-list')" x-ref="options" style="max-height: 15rem; overflow-y: auto;">
            <template x-for="(group, index) in choices" :key="group.id">
                <button type="button" role="option" tabindex="-1"
                        class="dropdown-item text-wrap px-3 py-2"
                        :class="{'active': active === index}" :id="$id('group-list') + '-' + index"
                        :aria-selected="Number($wire.formGroupId) === group.id"
                        @mousedown.prevent @mousemove="active = index" @click="choose(group.id)"
                        x-text="group.name"></button>
            </template>
        </div>
        <div x-show="loadingResults" class="text-muted small px-3 py-2" role="status">{{ __('organization_import.loading') }}</div>
        <div x-show="remoteFailed" class="text-danger small px-3 py-2" role="alert">{{ __('organization_import.search_failed') }}</div>
        <button type="button" x-show="hasMore && !loadingResults" class="btn btn-link" @click="loadMore()">{{ __('organization_import.more') }}</button>
        <div x-show="matches.length === 0 && !loadingResults && !remoteFailed" class="text-muted small px-3 py-2" role="status">{{ __('devices.no_matching_groups') }}</div>
    </div>
</div>
