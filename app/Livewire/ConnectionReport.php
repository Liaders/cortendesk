<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use App\Services\SessionWorkGroups;
use App\Support\ConnectionReportPresentation;
use App\Support\ReportWorkbook;
use App\Support\SessionTime;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Shuchkin\SimpleXLSXGen;

class ConnectionReport extends Component
{
    use AuthorizesConsole, WithPagination;

    public const EXPORT_LIMIT = 10000;

    public const TIMEZONE = ConnectionReportPresentation::TIMEZONE;

    public const TIMEZONE_LABEL = 'GMT+3';

    protected string $paginationTheme = 'bootstrap';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $source = [];

    public array $target = [];

    public string $workGroup = '';

    public bool $excludeInternal = false;

    public bool $excludeUnorganized = false;

    public string $organizationSort = '';

    public string $organizationDirection = 'asc';

    public function sortOrganizations(string $column): void
    {
        $this->authorizeConsole('audit');
        if (! in_array($column, ['name', 'count', 'seconds'], true)) {
            return;
        }
        $this->organizationDirection = $this->organizationSort === $column && $this->organizationDirection === 'asc' ? 'desc' : 'asc';
        $this->organizationSort = $column;
    }

    public function mount(): void
    {
        $this->authorizeConsole('audit');
        $this->period('this');
        $saved = auth()->user()->connection_report_filters;
        if (is_array($saved)) {
            $this->source = $this->cleanFilters($saved['source'] ?? [], ['device_group_id', 'user_id', 'user_group_id', 'rustdesk_id']);
            $this->target = $this->cleanFilters($saved['target'] ?? [], ['user_id', 'user_group_id', 'rustdesk_id']);
            $group = $saved['workGroup'] ?? '';
            $this->workGroup = is_string($group) && preg_match('/^(?:[1-9][0-9]*|unassigned)?$/D', $group) ? $group : '';
            $this->excludeInternal = ($saved['excludeInternal'] ?? false) === true;
            $this->excludeUnorganized = ($saved['excludeUnorganized'] ?? false) === true;
        }
    }

    private function cleanFilters(mixed $filters, array $keys): array
    {
        return is_array($filters) ? array_filter(array_intersect_key($filters, array_flip($keys)), fn ($value) => is_string($value) && mb_strlen($value) <= 255) : [];
    }

    public function period(string $period): void
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $start = $period === 'last' ? $now->subMonthNoOverflow()->startOfMonth() : $now->startOfMonth();
        $this->dateFrom = $start->format('j.n.Y');
        $this->dateTo = $start->endOfMonth()->format('j.n.Y');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        $this->authorizeConsole('audit');
        foreach (['source', 'target'] as $side) {
            if (($property === 'workGroup' && $side === 'target')
                || (str_starts_with($property, $side.'.') && $property !== $side.'.rustdesk_id')) {
                $id = $this->{$side}['rustdesk_id'] ?? '';
                if ($id !== '' && ! $this->deviceOptions($side)->where('rustdesk_id', $id)->exists()) {
                    $this->{$side}['rustdesk_id'] = '';
                }
            }
        }
        $this->resetPage();
        if (in_array($property, ['source', 'target', 'workGroup', 'excludeInternal', 'excludeUnorganized'], true) || str_starts_with($property, 'source.') || str_starts_with($property, 'target.')) {
            auth()->user()->forceFill(['connection_report_filters' => [
                'source' => $this->cleanFilters($this->source, ['device_group_id', 'user_id', 'user_group_id', 'rustdesk_id']),
                'target' => $this->cleanFilters($this->target, ['user_id', 'user_group_id', 'rustdesk_id']),
                'workGroup' => preg_match('/^(?:[1-9][0-9]*|unassigned)?$/D', $this->workGroup) ? $this->workGroup : '',
                'excludeInternal' => $this->excludeInternal,
                'excludeUnorganized' => $this->excludeUnorganized,
            ]])->save();
        }
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            // Accept ISO state from earlier clients as well as the visible D.M.YYYY format.
            $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? 'Y-m-d' : 'j.n.Y';
            $date = CarbonImmutable::createFromFormat('!'.$format, $value, self::TIMEZONE);
            $normalized = $format === 'Y-m-d' ? $value : preg_replace('/\b0(?=\d)/', '', $value);

            return $date && $date->format($format) === $normalized ? $date : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function bounds(): array
    {
        $this->authorizeConsole('audit');
        $this->validate([
            'dateFrom' => ['bail', 'required', 'string', 'max:10', function ($attribute, $value, $fail) {
                if (! $this->date($value)) {
                    $fail(__('validation.date_format', ['attribute' => __('report.date_from'), 'format' => 'D.M.YYYY']));
                }
            }],
            'dateTo' => ['bail', 'required', 'string', 'max:10', function ($attribute, $value, $fail) {
                if (! $this->date($value)) {
                    $fail(__('validation.date_format', ['attribute' => __('report.date_to'), 'format' => 'D.M.YYYY']));
                }
            }],
            'workGroup' => ['nullable', 'string', 'max:30', 'regex:/^(?:[1-9][0-9]*|unassigned)?$/'],
            'excludeInternal' => ['boolean'],
            'excludeUnorganized' => ['boolean'],
            'source' => 'array:device_group_id,user_id,user_group_id,rustdesk_id',
            'target' => 'array:user_id,user_group_id,rustdesk_id',
            'source.*' => 'nullable|string|max:255', 'target.*' => 'nullable|string|max:255',
        ]);

        $start = $this->date($this->dateFrom);
        $last = $this->date($this->dateTo);
        if ($last->lt($start)) {
            throw ValidationException::withMessages(['dateTo' => __('validation.after_or_equal', [
                'attribute' => __('report.date_to'), 'date' => $start->format('j.n.Y'),
            ])]);
        }

        return [$start, $last->addDay()];
    }

    private function devices(array $filters = [])
    {
        $q = Device::query()->visibleTo(auth()->user());
        foreach (['device_group_id', 'user_id', 'rustdesk_id'] as $field) {
            if (($filters[$field] ?? '') !== '' && ($filters[$field] ?? null) !== null) {
                $q->where($field, $filters[$field]);
            }
        }
        if (($filters['user_group_id'] ?? '') !== '') {
            $q->whereHas('user.groups', fn ($q) => $q->where('user_groups.id', $filters['user_group_id']));
        }

        return $q;
    }

    /** Only users whose identity this report viewer may already resolve. */
    private function operatorUsers()
    {
        $viewer = auth()->user();

        return User::query()->when(! $viewer->seesAllDevices(), fn ($q) => $q->where(fn ($q) => $q
            ->whereIn('id', $viewer->visibleUserIds())
            ->orWhereIn('id', $this->devices()->whereNotNull('user_id')->select('user_id'))));
    }

    private function deviceOptions(string $side)
    {
        $filters = $this->{$side};
        unset($filters['rustdesk_id']);
        $query = $this->devices($filters);
        if ($side === 'target' && $this->workGroup !== '') {
            $history = AuditConnection::whereNotNull('from_peer')->where('from_peer', '!=', '');
            if ($this->workGroup === 'unassigned') {
                $history->where('work_group_status', 'required');
                $query->whereIn('rustdesk_id', $history->select('rustdesk_id'));
            } else {
                $history->where('work_group_id', $this->workGroup);
                $query->where(fn ($q) => $q->where('device_group_id', $this->workGroup)
                    ->orWhereHas('workGroups', fn ($q) => $q->where('device_groups.id', $this->workGroup))
                    ->orWhereIn('rustdesk_id', $history->select('rustdesk_id')));
            }
        }

        return $query;
    }

    private function query()
    {
        [$start, $end] = $this->bounds();
        $q = AuditConnection::query()->with(['controlledDevice.group', 'workOrganization'])->whereNotNull('from_peer')->where('from_peer', '!=', '')
            ->whereIn('rustdesk_id', $this->devices($this->target)->select('rustdesk_id'))
            ->where('created_at', '<', $end->setTimezone(config('app.timezone')))
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $start->setTimezone(config('app.timezone'))));
        if (array_filter($this->source, fn ($v) => $v !== '' && $v !== null) !== []) {
            $webIds = collect();
            if (($this->source['device_group_id'] ?? '') === '' && ($this->source['rustdesk_id'] ?? '') === '') {
                $users = $this->operatorUsers()
                    ->when(($this->source['user_id'] ?? '') !== '', fn ($q) => $q->whereKey($this->source['user_id']))
                    ->when(($this->source['user_group_id'] ?? '') !== '', fn ($q) => $q->whereHas('groups', fn ($q) => $q->where('user_groups.id', $this->source['user_group_id'])));
                $webIds = $users->pluck('id')->map(fn ($id) => 'web-'.$id);
            }
            $q->where(fn ($q) => $q->whereIn('from_peer', $this->devices($this->source)->select('rustdesk_id'))->orWhereIn('from_peer', $webIds));
        }
        if ($this->workGroup === 'unassigned') {
            $q->where('work_group_status', 'required');
        } elseif ($this->workGroup !== '') {
            $q->where(function ($q) {
                $q->where('work_group_id', $this->workGroup)
                    ->orWhere(fn ($q) => $q->whereNull('work_group_status')->whereHas('controlledDevice', fn ($q) => $q->where('device_group_id', $this->workGroup)));
            });
        }

        if ($this->excludeUnorganized) {
            // Match presentation: legacy sessions use the device's primary organization.
            $q->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('work_group_status', ['automatic', 'selected'])->whereNotNull('work_group_id'))
                ->orWhere(fn ($q) => $q->whereNull('work_group_status')
                    ->whereHas('controlledDevice', fn ($q) => $q->whereNotNull('device_group_id'))));
        }

        if ($this->excludeInternal) {
            // Use the same legacy fallback as organization filtering and report presentation.
            // Confirmed and explicitly unassigned sessions never inherit target membership.
            $internal = $this->devices()->selectRaw('1')
                ->whereColumn('devices.rustdesk_id', 'audit_connections.from_peer')
                ->whereNotNull('devices.device_group_id')
                ->where(function ($q) {
                    $q->where(fn ($q) => $q
                        ->whereIn('audit_connections.work_group_status', ['automatic', 'selected'])
                        ->whereColumn('devices.device_group_id', 'audit_connections.work_group_id'))
                        ->orWhere(fn ($q) => $q->whereNull('audit_connections.work_group_status')
                            ->where('devices.device_group_id', Device::query()->select('device_group_id')
                                ->whereColumn('devices.rustdesk_id', 'audit_connections.rustdesk_id')->limit(1)));
                });
            $q->whereNotExists($internal->toBase());
        }

        return $q;
    }

    private function seconds($row, $start, $end): int
    {
        return $row->closed_at ? max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp)) : 0;
    }

    private function reportData($sessions = null): array
    {
        [$start, $end] = $this->bounds();
        $devices = $this->devices()->with(['user.groups', 'group', 'workGroups'])->get()->keyBy('rustdesk_id');
        $webUsers = $this->operatorUsers()->with('groups')->get()->keyBy('id');
        $total = $active = $count = 0;
        $intervals = $breakdown = $operators = [];
        foreach ($sessions ?? $this->query()->lazyById(1000) as $row) {
            $count++;
            if (! $row->closed_at) {
                $active++;

                continue;
            }
            $seconds = $this->seconds($row, $start, $end);
            $total += $seconds;
            $operator = ConnectionReportPresentation::operatorKey($row, $devices, $webUsers);
            $intervals[$operator][] = [max($start->timestamp, $row->created_at->timestamp), min($end->timestamp, $row->closed_at->timestamp)];
            $operators[$operator] ??= ['name' => ConnectionReportPresentation::operator($row, $devices, $webUsers), 'count' => 0, 'seconds' => 0];
            $operators[$operator]['count']++;
            $operators[$operator]['seconds'] += $seconds;
            $key = $row->work_group_status === null ? ($devices->get($row->rustdesk_id)?->device_group_id ?? 'ungrouped') : ($row->work_group_id ?? $row->work_group_status);
            $breakdown[$key] ??= ['name' => SessionWorkGroups::label($row), 'count' => 0, 'seconds' => 0];
            $breakdown[$key]['count']++;
            $breakdown[$key]['seconds'] += $seconds;
        }

        return [
            'devices' => $devices, 'webUsers' => $webUsers, 'total' => $total, 'active' => $active, 'count' => $count,
            'unique' => SessionTime::withoutOverlaps($intervals), 'breakdown' => $breakdown,
            'start' => $start, 'end' => $end, 'operators' => $operators,
        ];
    }

    public function exportXlsx()
    {
        $matchedCount = $this->query()->count();
        $exportRows = $this->query()->orderBy('id')->limit(self::EXPORT_LIMIT)->get();
        $data = $this->reportData($exportRows);
        $text = fn ($value) => ReportWorkbook::text($value);
        $duration = fn (int $seconds) => ReportWorkbook::duration($seconds);
        $heading = fn (array $cells) => ReportWorkbook::heading($cells);
        $summary = [
            $heading([__('report.title'), __('report.value')]),
            [$text(__('report.date_from')), ReportWorkbook::date($data['start'])],
            [$text(__('report.date_to')), ReportWorkbook::date($data['end']->subDay())],
            [$text(__('report.timezone')), $text(self::TIMEZONE_LABEL)],
        ];
        foreach ($this->filterSummary($data) as $filter) {
            $summary[] = [$text($filter['label']), $text($filter['value'])];
        }
        $summary[] = [$text(__('report_preferences.exclude_internal')), $text($this->excludeInternal ? __('report_preferences.yes') : __('report_preferences.no'))];
        $summary[] = [$text(__('report_preferences.exclude_unorganized')), $text($this->excludeUnorganized ? __('report_preferences.yes') : __('report_preferences.no'))];
        array_push($summary,
            [$text(__('report.matching_sessions')), ReportWorkbook::number($matchedCount)],
            [$text(__('report.exported_sessions')), ReportWorkbook::number($data['count'])],
            [$text(__('report.truncated')), $text($matchedCount > self::EXPORT_LIMIT ? __('report.truncated_yes') : __('report.truncated_no'))],
            [$text(__('report.active')), ReportWorkbook::number($data['active'])],
            [$text(__('report.duration')), $duration($data['total'])],
            [$text(__('report.unique')), $duration($data['unique'])],
            [$text(__('report.method')), $text(__('report.help', ['timezone' => self::TIMEZONE_LABEL]))],
            [$text(__('report.attribution_title')), $text(__('report.attribution').' '.__('console_usability.report_note'))],
            [$text(__('work_groups.work_group')), $text(__('work_groups.report_help'))],
        );
        $xlsx = SimpleXLSXGen::fromArray($summary, __('report.sheet_summary'))
            ->setTitle(__('report.title'))->setLanguage(app()->getLocale())
            ->setColWidth(1, 48)->setColWidth(2, 100)->freezePanes('A2');
        foreach (['breakdown' => __('report.sheet_organizations'), 'operators' => __('report.sheet_operators')] as $key => $name) {
            $rows = [$heading([$key === 'operators' ? __('report.from') : __('work_groups.work_group'), __('report.completed_sessions'), __('report.duration')])];
            foreach ($data[$key] as $row) {
                $rows[] = [$text($row['name']), ReportWorkbook::number($row['count']), $duration($row['seconds'])];
            }
            $xlsx->addSheet($rows, $name)->setColWidth(1, 40)->setColWidth(2, 22)->setColWidth(3, 24)->freezePanes('A2');
        }
        $sessions = [$heading([__('report.from_id'), __('report.from'), __('report.to_device'), __('report.start'), __('report.end'), __('report.seconds'), __('report.type'), __('report.note'), __('work_groups.work_group')])];
        foreach ($exportRows as $row) {
            // Raw cells keep IDs as text and prevent formulas, markup or links in client data.
            $sessions[] = [$text($row->from_peer), $text(ConnectionReportPresentation::operator($row, $data['devices'], $data['webUsers'])), $text(ConnectionReportPresentation::device($data['devices']->get($row->rustdesk_id), $row->rustdesk_id)),
                ReportWorkbook::date($row->created_at?->copy()->setTimezone(self::TIMEZONE), true), ReportWorkbook::date($row->closed_at?->copy()->setTimezone(self::TIMEZONE), true),
                $row->closed_at ? $duration($this->seconds($row, $data['start'], $data['end'])) : null,
                $text(AuditConnection::typeLabel((int) $row->conn_type)), $text($row->note), $text(SessionWorkGroups::label($row))];
        }
        $xlsx->addSheet($sessions, __('report.sheet_sessions'))->freezePanes('A2')->autoFilter('A1:I'.count($sessions))
            ->setColWidth('A', 20)->setColWidth('B:C', 34)->setColWidth('D:E', 24)->setColWidth('F', 22)->setColWidth('G', 28)->setColWidth('H', 60)->setColWidth('I', 32);

        return response()->streamDownload(function () use ($xlsx) {
            echo (string) $xlsx;
        }, 'connection-report.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function workGroups(Collection $devices): Collection
    {
        $groups = $devices->flatMap(fn ($device) => SessionWorkGroups::choices($device))->map(fn ($group) => ['id' => $group->id, 'name' => $group->name]);
        $latest = AuditConnection::whereIn('rustdesk_id', $devices->keys())->whereNotNull('from_peer')->where('from_peer', '!=', '')
            ->whereNotNull('work_group_id')->groupBy('work_group_id')->selectRaw('MAX(id)');
        $historical = AuditConnection::whereIn('id', $latest)->with('workOrganization')->get(['work_group_id', 'work_group_name'])
            ->map(fn ($row) => ['id' => $row->work_group_id, 'name' => $row->workOrganization?->name ?? $row->work_group_name]);

        return $groups->concat($historical)->unique('id')->sortBy('name')->values();
    }

    private function filterSummary(array $data): array
    {
        $devices = $data['devices'];
        $groups = $devices->pluck('group')->filter()->keyBy('id');
        $users = $devices->pluck('user')->filter()->concat($data['webUsers'] ?? [])->keyBy('id');
        $userGroups = $users->flatMap(fn ($user) => $user->groups)->keyBy('id');
        $sourceGroup = $this->source['device_group_id'] ?? '';
        $workGroups = ($data['workGroups'] ?? $this->workGroups($devices))->keyBy('id');
        $result = [
            ['label' => __('report.from'), 'value' => $sourceGroup !== '' ? ($groups->get($sourceGroup)?->name ?? __('report.unavailable')) : __('report.all_organizations')],
            ['label' => __('report.to'), 'value' => match ($this->workGroup) {
                '' => __('report.all_organizations'),
                'unassigned' => __('work_groups.unassigned'),
                default => $workGroups->get($this->workGroup)['name'] ?? __('report.unavailable'),
            }],
        ];
        foreach (['source' => __('report.from'), 'target' => __('report.to')] as $side => $label) {
            foreach (['user_id' => 'user', 'user_group_id' => 'user_group', 'rustdesk_id' => 'device'] as $field => $key) {
                $id = $this->{$side}[$field] ?? '';
                $value = match ($field) {
                    'user_id' => ($user = $users->get($id)) ? (trim((string) $user->name) ?: $user->username) : null,
                    'user_group_id' => $userGroups->get($id)?->name,
                    'rustdesk_id' => ($device = $devices->get($id)) ? ConnectionReportPresentation::device($device) : null,
                };
                $result[] = ['label' => $label.' · '.__('report.'.$key), 'value' => $id !== '' ? ($value ?? __('report.unavailable')) : __('report.all_'.$key)];
            }
        }

        return $result;
    }

    public function render()
    {
        try {
            $data = $this->reportData();
            $data['rows'] = $this->query()->orderByDesc('created_at')->orderByDesc('id')->paginate(25);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $data = [
                'devices' => $this->devices()->with(['user.groups', 'group', 'workGroups'])->get()->keyBy('rustdesk_id'),
                'webUsers' => $this->operatorUsers()->with('groups')->get()->keyBy('id'),
                'total' => 0, 'active' => 0, 'count' => 0, 'unique' => 0,
                'breakdown' => [], 'operators' => [], 'start' => now(), 'end' => now(),
                'rows' => new LengthAwarePaginator([], 0, 25),
            ];
        }

        if (in_array($this->organizationSort, ['name', 'count', 'seconds'], true)) {
            $data['breakdown'] = collect($data['breakdown'])->sortBy([
                [$this->organizationSort, $this->organizationDirection === 'desc' ? 'desc' : 'asc'],
                ['name', 'asc'],
            ])->all();
        }
        $data['workGroups'] = $this->workGroups($data['devices']);
        $data['filterSummary'] = $this->filterSummary($data);
        $data['sourceDevices'] = $this->deviceOptions('source')->orderBy('rustdesk_id')->get();
        $data['targetDevices'] = $this->deviceOptions('target')->orderBy('rustdesk_id')->get();

        return view('livewire.connection-report', $data);
    }
}
