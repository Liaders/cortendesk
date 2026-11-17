<?php

namespace App\Livewire\Concerns;

use App\Models\OrganizationImportBatch;
use App\Models\UserGroup;
use App\Rules\OrganizationInn;
use App\Services\OrganizationImport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

trait ImportsOrganizations
{
    public bool $showImport = false;

    public $importFile = null;

    #[Locked]
    public ?string $importBatchId = null;

    public bool $importRename = false;

    public bool $importSkipErrors = false;

    public array $importAccessorIds = [];

    public string $importFilter = 'all';

    #[Locked]
    public ?int $importEditingRow = null;

    public string $importRowName = '';

    public string $importRowInn = '';

    public function openImport(): void
    {
        $this->authorizeConsole('group', 'rw');
        abort_if($this->deviceEditor, 403);
        $this->reset('importFile', 'importBatchId', 'importRename', 'importSkipErrors', 'importFilter', 'importEditingRow');
        $own = auth()->user()->userGroupIds();
        $this->importAccessorIds = count($own) === 1 ? $own : [];
        $this->showImport = true;
        $this->resetPage('importPage');
        $this->resetValidation();
    }

    public function closeImport(): void
    {
        $this->showImport = false;
        $this->reset('importFile', 'importEditingRow');
    }

    private function importBatch(): OrganizationImportBatch
    {
        $this->authorizeConsole('group', 'rw');
        $batch = OrganizationImportBatch::whereKey($this->importBatchId)->where('user_id', auth()->id())->firstOrFail();
        abort_if(! $batch->applied_at && $batch->expires_at->isPast(), 409, __('organization_import.expired'));

        return $batch;
    }

    public function previewImport(): void
    {
        $this->authorizeConsole('group', 'rw');
        $this->validate(['importFile' => ['required', 'file', 'max:2048', 'extensions:txt']]);
        $parsed = OrganizationImport::parse(file_get_contents($this->importFile->getRealPath()));
        $batch = OrganizationImport::createBatch(auth()->user(), $parsed['rows'], $this->importRename);
        $this->importBatchId = $batch->id;
        $this->importFile = null;
        $this->resetPage('importPage');
    }

    public function updatedImportRename(): void
    {
        if ($this->importBatchId) {
            $this->refreshImport();
        }
    }

    public function updatedImportFilter(): void
    {
        $this->resetPage('importPage');
    }

    public function refreshImport(): void
    {
        $batch = $this->importBatch();
        abort_if($batch->applied_at, 409);
        $batch->update(['preview' => OrganizationImport::plan($batch->rows, $this->importRename)]);
        $this->resetValidation('importFile');
    }

    public function editImportRow(int $index): void
    {
        $batch = $this->importBatch();
        abort_if($batch->applied_at || ! isset($batch->rows[$index]), 404);
        $this->importEditingRow = $index;
        $this->importRowName = $batch->rows[$index]['name'];
        $this->importRowInn = $batch->rows[$index]['inn'];
        $this->resetValidation();
    }

    public function cancelImportRow(): void
    {
        $this->importEditingRow = null;
    }

    public function saveImportRow(): void
    {
        $batch = $this->importBatch();
        abort_if($batch->applied_at || ! isset($batch->rows[$this->importEditingRow]), 404);
        $this->importRowName = trim($this->importRowName);
        $this->importRowInn = trim($this->importRowInn);
        $this->validate([
            'importRowName' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'importRowInn' => ['nullable', 'string', new OrganizationInn],
        ]);
        $rows = $batch->rows;
        $rows[$this->importEditingRow] = ['line' => $rows[$this->importEditingRow]['line'], 'name' => $this->importRowName, 'inn' => $this->importRowInn, 'parse_error' => null];
        $batch->update(['rows' => $rows, 'preview' => OrganizationImport::plan($rows, $this->importRename)]);
        $this->importEditingRow = null;
    }

    public function applyImport(): void
    {
        $this->authorizeConsole('group', 'rw');
        $this->validate([
            'importAccessorIds' => ['array'],
            'importAccessorIds.*' => ['integer', 'distinct', Rule::exists('user_groups', 'id')],
        ]);
        OrganizationImport::apply(auth()->user(), $this->importBatchId ?? '', array_map('intval', $this->importAccessorIds), $this->importRename, $this->importSkipErrors);
        $this->importEditingRow = null;
    }

    protected function importViewData(): array
    {
        $batch = $this->showImport && $this->importBatchId ? $this->importBatch() : null;
        $rows = collect($batch?->preview ?? [])->map(fn ($row, $i) => $row + ['index' => $i]);
        $counts = array_fill_keys(['create', 'update', 'skip', 'error'], 0);
        foreach ($rows as $row) {
            $counts[$row['action']]++;
        }
        $filtered = $this->importFilter === 'all' ? $rows : $rows->where('action', $this->importFilter);
        $page = max(1, min($this->getPage('importPage'), max(1, (int) ceil($filtered->count() / 25))));

        return [
            'importResult' => $batch?->result, 'importCounts' => $counts,
            'importRows' => new LengthAwarePaginator($filtered->values()->forPage($page, 25), $filtered->count(), 25, $page, ['pageName' => 'importPage']),
            'importUserGroups' => $this->showImport ? UserGroup::query()->when(! auth()->user()->is_admin, fn ($q) => $q->whereIn('id', auth()->user()->userGroupIds()))->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }
}
