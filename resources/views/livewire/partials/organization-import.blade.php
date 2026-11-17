@if ($showImport)
<div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="organization-import-title">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="organization-import-title">{{ __('organization_import.import') }}</h5><button type="button" class="btn-close" aria-label="{{ __('common.close') }}" wire:click="closeImport"></button></div>
        <div class="modal-body">
            @error('importFile')<div class="alert alert-danger">{{ $message }}</div>@enderror
            @if ($importResult)
                <div class="alert alert-success">{{ __('organization_import.completed', $importResult) }}</div>
            @else
                <p>{{ __('organization_import.help') }}</p>
                @if (! $importBatchId)
                    <label class="form-label" for="organization-import-file">{{ __('organization_import.file') }}</label>
                    <input id="organization-import-file" type="file" accept=".txt,text/plain" class="form-control" wire:model="importFile">
                    <button type="button" class="btn btn-primary mt-3" wire:click="previewImport" wire:loading.attr="disabled">{{ __('organization_import.preview') }}</button>
                @else
                    <div class="row g-3 mb-3">@foreach ($importCounts as $action => $count)<div class="col-6 col-md-3"><span class="text-muted">{{ __('organization_import.action_'.$action) }}</span><strong class="d-block fs-4">{{ $count }}</strong></div>@endforeach</div>
                    <label class="form-check mb-2"><input type="checkbox" class="form-check-input" wire:model.live="importRename"><span class="form-check-label">{{ __('organization_import.rename') }}</span></label>
                    <label class="form-check mb-3"><input type="checkbox" class="form-check-input" wire:model.live="importSkipErrors"><span class="form-check-label">{{ __('organization_import.skip_errors') }}</span></label>
                    <details class="mb-3"><summary>{{ __('organization_import.new_access') }}</summary><p class="text-muted mt-2">{{ __('organization_import.access_help') }}</p>
                        @forelse ($importUserGroups as $group)<label class="form-check"><input class="form-check-input" type="checkbox" value="{{ $group->id }}" wire:model="importAccessorIds"><span class="form-check-label">{{ $group->name }}</span></label>@empty<p>{{ __('groups.no_other_user_groups_exist') }}</p>@endforelse
                    </details>
                    <div class="d-flex flex-wrap gap-2 mb-3"><select class="form-select w-auto" aria-label="{{ __('organization_import.filter') }}" wire:model.live="importFilter"><option value="all">{{ __('organization_import.all_rows') }}</option>@foreach (['error','create','update','skip'] as $action)<option value="{{ $action }}">{{ __('organization_import.action_'.$action) }}</option>@endforeach</select><button type="button" class="btn btn-light" wire:click="refreshImport">{{ __('organization_import.refresh') }}</button></div>
                    @if ($importEditingRow !== null)
                        <div class="border rounded p-3 mb-3"><h6>{{ __('organization_import.edit_row') }}</h6><label class="form-label" for="import-row-name">{{ __('common.name') }}</label><input id="import-row-name" class="form-control mb-2" wire:model="importRowName">@error('importRowName')<p class="text-danger">{{ $message }}</p>@enderror<label class="form-label" for="import-row-inn">{{ __('organization_import.inn') }}</label><input id="import-row-inn" inputmode="numeric" class="form-control mb-2" wire:model="importRowInn">@error('importRowInn')<p class="text-danger">{{ $message }}</p>@enderror<button class="btn btn-primary" wire:click="saveImportRow">{{ __('common.save') }}</button><button class="btn btn-light ms-2" wire:click="cancelImportRow">{{ __('common.cancel') }}</button></div>
                    @endif
                    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>{{ __('organization_import.line') }}</th><th>{{ __('common.name') }}</th><th>{{ __('organization_import.inn') }}</th><th>{{ __('organization_import.result') }}</th><th>{{ __('common.action') }}</th></tr></thead><tbody>
                        @foreach ($importRows as $row)<tr wire:key="import-row-{{ $row['index'] }}"><td>{{ $row['line'] }}</td><td>{{ \Illuminate\Support\Str::limit($row['name'], 255) }}@if ($row['before_name'] && $row['before_name'] !== $row['name'])<small class="d-block text-muted">{{ __('organization_import.existing') }}: {{ $row['before_name'] }}</small>@endif</td><td class="text-nowrap">{{ $row['inn'] !== '' ? \Illuminate\Support\Str::limit($row['inn'], 24) : '—' }}</td><td><span class="{{ $row['action'] === 'error' ? 'text-danger' : '' }}">{{ __('organization_import.action_'.$row['action']) }}</span>@if ($row['reason'])<small class="d-block">{{ __('organization_import.'.$row['reason']) }}</small>@endif</td><td><button class="btn btn-sm btn-light" wire:click="editImportRow({{ $row['index'] }})">{{ __('common.edit') }}</button></td></tr>@endforeach
                    </tbody></table></div>
                    {{ $importRows->links() }}
                @endif
            @endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeImport">{{ __('common.close') }}</button>
            @if ($importBatchId && ! $importResult)<button type="button" class="btn btn-primary" wire:click="applyImport" wire:loading.attr="disabled" @disabled($importEditingRow !== null || ($importCounts['error'] > 0 && ! $importSkipErrors))>{{ __('organization_import.apply') }}</button>@endif
        </div>
    </div></div>
</div><div class="modal-backdrop fade show"></div>
@endif
