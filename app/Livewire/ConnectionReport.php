<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Services\SessionWorkGroups;
use App\Support\Csv;
use App\Support\SessionTime;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Shuchkin\SimpleXLSXGen;

class ConnectionReport extends Component
{
    use AuthorizesConsole, WithPagination;

    public const EXPORT_LIMIT = 10000;

    protected string $paginationTheme = 'bootstrap';

    public string $dateFrom = '';

    public string $dateTo = '';

    public array $source = [];

    public array $target = [];

    public string $workGroup = '';

    public function mount(): void
    {
        $this->authorizeConsole('audit');
        $this->period('this');
    }

    public function period(string $period): void
    {
        $start = $period === 'last' ? now()->subMonthNoOverflow()->startOfMonth() : now()->startOfMonth();
        $this->dateFrom = $start->toDateString();
        $this->dateTo = $start->copy()->endOfMonth()->toDateString();
        $this->resetPage();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function bounds(): array
    {
        $this->authorizeConsole('audit');
        $this->validate([
            'dateFrom' => 'required|date_format:Y-m-d',
            'dateTo' => 'required|date_format:Y-m-d|after_or_equal:dateFrom',
            'workGroup' => ['nullable', 'string', 'max:30', 'regex:/^(?:[1-9][0-9]*|unassigned)?$/'],
            'source' => 'array:device_group_id,user_id,user_group_id,rustdesk_id',
            'target' => 'array:device_group_id,user_id,user_group_id,rustdesk_id',
            'source.*' => 'nullable|string|max:255', 'target.*' => 'nullable|string|max:255',
        ]);

        return [CarbonImmutable::parse($this->dateFrom)->startOfDay(), CarbonImmutable::parse($this->dateTo)->addDay()->startOfDay()];
    }

    private function devices(array $filters = [])
    {
        $q = Device::query()->visibleTo(auth()->user());
        foreach (['device_group_id', 'user_id', 'rustdesk_id'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $q->where($field, $filters[$field]);
            }
        }
        if (($filters['user_group_id'] ?? '') !== '') {
            $q->whereHas('user.groups', fn ($q) => $q->where('user_groups.id', $filters['user_group_id']));
        }

        return $q;
    }

    private function query()
    {
        [$start, $end] = $this->bounds();
        $q = AuditConnection::query()->with('controlledDevice.group')->whereNotNull('from_peer')->where('from_peer', '!=', '')
            ->whereIn('rustdesk_id', $this->devices($this->target)->select('rustdesk_id'))
            ->where('created_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $start));
        if (array_filter($this->source, fn ($v) => $v !== '') !== []) {
            $q->whereIn('from_peer', $this->devices($this->source)->select('rustdesk_id'));
        }
        if ($this->workGroup === 'unassigned') {
            $q->where('work_group_status', 'required');
        } elseif ($this->workGroup !== '') {
            $q->where(function ($q) {
                $q->where('work_group_id', $this->workGroup)
                    ->orWhere(fn ($q) => $q->whereNull('work_group_status')->whereHas('controlledDevice', fn ($q) => $q->where('device_group_id', $this->workGroup)));
            });
        }

        return $q;
    }

    private function seconds($row, $start, $end): int
    {
        return $row->closed_at ? max(0, min($end->timestamp, $row->closed_at->timestamp) - max($start->timestamp, $row->created_at->timestamp)) : 0;
    }

    public function export()
    {
        [$start, $end] = $this->bounds();
        $query = $this->query()->orderBy('id')->limit(self::EXPORT_LIMIT)->get();

        return response()->streamDownload(function () use ($query, $start, $end) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['From ID', 'From name', 'To ID', 'Started', 'Closed', 'Seconds in period', 'Type', 'Note', 'Work group']);
            foreach ($query as $row) {
                fputcsv($out, Csv::row([$row->from_peer, $row->from_name, $row->rustdesk_id, $row->created_at?->toDateTimeString(), $row->closed_at?->toDateTimeString(), $row->closed_at ? $this->seconds($row, $start, $end) : '', AuditConnection::englishTypeLabel((int) $row->conn_type), $row->note, SessionWorkGroups::label($row, 'en')]));
            }
            fclose($out);
        }, 'connection-report.csv');
    }

    private function reportData($sessions = null): array
    {
        [$start, $end] = $this->bounds();
        $devices = $this->devices()->with(['user.groups', 'group', 'workGroups'])->get()->keyBy('rustdesk_id');
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
            $device = $devices->get($row->from_peer);
            $operator = $device?->user_id ? 'user:'.$device->user_id : 'device:'.$row->from_peer;
            $intervals[$operator][] = [max($start->timestamp, $row->created_at->timestamp), min($end->timestamp, $row->closed_at->timestamp)];
            $operators[$operator] ??= ['name' => $device?->user?->username ?? $row->from_peer, 'count' => 0, 'seconds' => 0];
            $operators[$operator]['count']++;
            $operators[$operator]['seconds'] += $seconds;
            $key = $row->work_group_status === null ? ($devices->get($row->rustdesk_id)?->device_group_id ?? 0) : ($row->work_group_id ?? $row->work_group_status);
            $breakdown[$key] ??= ['name' => SessionWorkGroups::label($row), 'count' => 0, 'seconds' => 0];
            $breakdown[$key]['count']++;
            $breakdown[$key]['seconds'] += $seconds;
        }

        return [
            'devices' => $devices, 'total' => $total, 'active' => $active, 'count' => $count,
            'unique' => SessionTime::withoutOverlaps($intervals), 'breakdown' => $breakdown,
            'start' => $start, 'end' => $end, 'operators' => $operators,
        ];
    }

    public function exportXlsx()
    {
        $matchedCount = $this->query()->count();
        $exportRows = $this->query()->orderBy('id')->limit(self::EXPORT_LIMIT)->get();
        $data = $this->reportData($exportRows);
        $text = fn ($value) => "\0".(string) $value;
        $duration = fn (int $seconds) => '<style nf="[h]:mm:ss">'.($seconds / 86400).'</style>';
        $heading = fn (array $cells) => array_map(fn ($cell) => '<b>'.$cell.'</b>', $cells);
        $summary = [
            $heading(['Connection report', 'Value']),
            ['From date', $text($this->dateFrom)],
            ['To date (inclusive)', $text($this->dateTo)],
            ['Timezone', $text(config('app.timezone'))],
            ['Source filters', $text(json_encode($this->source, JSON_UNESCAPED_UNICODE))],
            ['Target filters', $text(json_encode($this->target, JSON_UNESCAPED_UNICODE))],
            ['Work group filter', $text($this->workGroup)],
            ['Matching sessions', $matchedCount],
            ['Exported sessions', $data['count']],
            ['Export truncated', $matchedCount > self::EXPORT_LIMIT ? 'Yes: first 10000 sessions only; summary totals describe exported sessions.' : 'No'],
            ['Unfinished sessions (excluded from time totals)', $data['active']],
            ['Total session time', $duration($data['total'])],
            ['Time without overlaps per operator', $duration($data['unique'])],
            ['Calculation', $text(__('report.help', ['timezone' => config('app.timezone')], 'en'))],
            ['Attribution', $text(__('work_groups.report_help', [], 'en'))],
        ];
        $xlsx = SimpleXLSXGen::fromArray($summary, 'Summary')->setColWidth(1, 48)->setColWidth(2, 100)->freezePanes('A2');
        foreach (['breakdown' => 'Work groups', 'operators' => 'Operators'] as $key => $name) {
            $rows = [$heading([$name, 'Completed sessions', 'Total session time'])];
            foreach ($data[$key] as $row) {
                $rows[] = [$text($row['name']), $row['count'], $duration($row['seconds'])];
            }
            $xlsx->addSheet($rows, $name)->setColWidth(1, 40)->setColWidth(2, 22)->setColWidth(3, 24)->freezePanes('A2');
        }
        $sessions = [$heading(['From ID', 'From name', 'To ID', 'Started', 'Closed', 'Time in period', 'Type', 'Note', 'Work group'])];
        foreach ($exportRows as $row) {
            // Raw cells keep IDs as text and prevent formulas, markup or links in client data.
            $sessions[] = [$text($row->from_peer), $text($row->from_name), $text($row->rustdesk_id),
                $row->created_at?->toDateTimeString(), $row->closed_at?->toDateTimeString(),
                $row->closed_at ? $duration($this->seconds($row, $data['start'], $data['end'])) : null,
                $text(AuditConnection::englishTypeLabel((int) $row->conn_type)), $text($row->note), $text(SessionWorkGroups::label($row, 'en'))];
        }
        $xlsx->addSheet($sessions, 'Sessions')->freezePanes('A2')->autoFilter('A1:I'.count($sessions))
            ->setColWidth('A:C', 24)->setColWidth('D:E', 22)->setColWidth('F:G', 22)->setColWidth('H', 60)->setColWidth('I', 32);

        return response()->streamDownload(function () use ($xlsx) {
            echo (string) $xlsx;
        }, 'connection-report.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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
                'total' => 0, 'active' => 0, 'count' => 0, 'unique' => 0,
                'breakdown' => [], 'operators' => [], 'start' => now(), 'end' => now(),
                'rows' => new LengthAwarePaginator([], 0, 25),
            ];
        }

        $groups = $data['devices']->flatMap(fn ($device) => SessionWorkGroups::choices($device))->map(fn ($group) => ['id' => $group->id, 'name' => $group->name]);
        $historical = AuditConnection::whereIn('rustdesk_id', $data['devices']->keys())->whereNotNull('work_group_id')
            ->select('work_group_id as id', 'work_group_name as name')->distinct()->get()->toArray();
        $data['workGroups'] = $groups->concat($historical)->unique('id')->sortBy('name');

        return view('livewire.connection-report', $data);
    }
}
