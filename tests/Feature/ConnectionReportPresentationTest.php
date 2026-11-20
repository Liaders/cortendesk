<?php

use App\Livewire\ConnectionReport;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Shuchkin\SimpleXLSXGen;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    $this->admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true, 'locale' => 'ru']);
    $this->actingAs($this->admin);
    app()->setLocale('ru');
    $this->support = DeviceGroup::create(['name' => 'Наша поддержка']);
    $this->primary = DeviceGroup::create(['name' => 'Бухгалтерская компания']);
    $this->a = DeviceGroup::create(['name' => 'Узорметалл']);
    $this->b = DeviceGroup::create(['name' => 'Стройсервис']);
    $this->staff = User::create(['username' => 'svetlana', 'name' => 'Светлана Иванова', 'password' => 'test']);
    $this->source = reportPresentationDevice('000101', $this->support, $this->staff);
    $this->target = reportPresentationDevice('18446744073709551614', $this->primary, null, 'Бухгалтерия');
    $this->target->workGroups()->attach([$this->a->id, $this->b->id]);
    $this->other = reportPresentationDevice('000303', $this->b);
});

function reportPresentationDevice(string $id, ?DeviceGroup $group = null, ?User $owner = null, ?string $alias = null): Device
{
    return Device::withoutEvents(fn () => Device::create([
        'rustdesk_id' => $id, 'uuid' => $id, 'device_group_id' => $group?->id,
        'user_id' => $owner?->id, 'alias' => $alias, 'status' => 'active',
    ]));
}

function reportPresentationSession(Device $target, Device $source, ?DeviceGroup $organization, string $start = '2026-11-01 06:00:00', ?string $end = '2026-11-01 07:00:00', array $extra = []): AuditConnection
{
    $row = new AuditConnection($extra + [
        'rustdesk_id' => $target->rustdesk_id, 'from_peer' => $source->rustdesk_id,
        'from_name' => 'client-login', 'closed_at' => $end, 'action' => 'close', 'conn_type' => 0,
    ]);
    $row->created_at = $start;
    $row->forceFill([
        'work_group_id' => $organization?->id, 'work_group_name' => $organization?->name,
        'work_group_status' => $organization ? 'selected' : 'required',
    ])->save();

    return $row;
}

/** Inspect real XLSX cell types, values, alignment and formats, not exporter internals. */
function reportPresentationWorkbook(ConnectionReport $report): array
{
    ob_start();
    $report->exportXlsx()->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'report-presentation-');
    $zip = new ZipArchive;
    try {
        file_put_contents($path, $bytes);
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Invalid XLSX export.');
        }
        $parse = function (string $xml): DOMDocument {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml)) {
                throw new RuntimeException('Invalid workbook XML.');
            }

            return $doc;
        };
        $strings = [];
        foreach ($parse($zip->getFromName('xl/sharedStrings.xml'))->getElementsByTagName('si') as $node) {
            $strings[] = $node->textContent;
        }
        $styles = $parse($zip->getFromName('xl/styles.xml'));
        $formats = [];
        foreach ($styles->getElementsByTagName('numFmt') as $node) {
            $formats[$node->getAttribute('numFmtId')] = $node->getAttribute('formatCode');
        }
        $styleRows = $styles->getElementsByTagName('cellXfs')->item(0)->getElementsByTagName('xf');
        $result = [];
        foreach ($parse($zip->getFromName('xl/workbook.xml'))->getElementsByTagName('sheet') as $i => $sheet) {
            $xml = $zip->getFromName('xl/worksheets/sheet'.($i + 1).'.xml');
            $document = $parse($xml);
            $cells = [];
            foreach ($document->getElementsByTagName('c') as $cell) {
                $type = $cell->getAttribute('t');
                $raw = $cell->getElementsByTagName('v')->item(0)?->textContent;
                $value = match ($type) {
                    's' => $raw !== null ? $strings[(int) $raw] : '',
                    'inlineStr' => $cell->getElementsByTagName('is')->item(0)?->textContent,
                    default => $raw,
                };
                $style = $styleRows->item((int) $cell->getAttribute('s'));
                $cells[$cell->getAttribute('r')] = [
                    'value' => $value, 'type' => $type,
                    'align' => $style->getElementsByTagName('alignment')->item(0)?->getAttribute('horizontal'),
                    'format' => $formats[$style->getAttribute('numFmtId')] ?? null,
                ];
            }
            $result[$sheet->getAttribute('name')] = ['cells' => $cells, 'xml' => $xml];
        }

        return $result;
    } finally {
        $zip->close();
        unlink($path);
    }
}

it('merges web and owned-device sessions by user ID while keeping unknown sources explicit', function () {
    reportPresentationSession($this->target, $this->source, $this->a);
    $web = new Device(['rustdesk_id' => 'web-'.$this->staff->id]);
    reportPresentationSession($this->target, $web, $this->a, '2026-11-01 06:30:00', '2026-11-01 07:30:00');
    $unknown = new Device(['rustdesk_id' => 'missing-source']);
    reportPresentationSession($this->target, $unknown, $this->a, extra: ['from_name' => $this->staff->name]);
    $key = 'user:'.$this->staff->id;
    Livewire::test(ConnectionReport::class)
        ->assertViewHas('operators', fn ($rows) => count($rows) === 2 && $rows[$key]['count'] === 2 && $rows[$key]['seconds'] === 7200)
        ->assertViewHas('unique', 9000)
        ->assertSee('Светлана Иванова (missing-source)')
        ->set('source.user_id', '0')->assertViewHas('count', 0)
        ->set('source.user_id', (string) $this->staff->id)->assertViewHas('count', 2)
        ->set('source.rustdesk_id', $this->source->rustdesk_id)->assertViewHas('count', 1);
    $report = new ConnectionReport;
    $report->mount();
    $book = reportPresentationWorkbook($report);
    expect($book['По сотрудникам']['cells']['A2']['value'])->toBe('Светлана Иванова');
    expect($book['По сотрудникам']['cells']['B2']['value'])->toBe('2');
    expect(array_column($book['По сотрудникам']['cells'], 'value'))->toContain('Светлана Иванова (missing-source)');
});

it('shows current accounting organization names in every report surface without rewriting snapshots', function () {
    $row = reportPresentationSession($this->target, $this->source, $this->a);
    $this->a->update(['name' => 'Актуальное название']);
    Livewire::test(ConnectionReport::class)->set('workGroup', (string) $this->a->id)
        ->assertViewHas('breakdown', fn ($groups) => $groups[$this->a->id]['name'] === 'Актуальное название')
        ->assertSee('Актуальное название')->assertDontSee('Узорметалл');
    expect($row->fresh()->work_group_name)->toBe('Узорметалл');
    $report = new ConnectionReport;
    $report->mount();
    $report->workGroup = (string) $this->a->id;
    $book = reportPresentationWorkbook($report);
    expect(array_column($book['Организации']['cells'], 'value'))->toContain('Актуальное название')->not->toContain('Узорметалл');
    expect(array_column($book['Сеансы']['cells'], 'value'))->toContain('Актуальное название')->not->toContain('Узорметалл');
    $this->a->delete();
    Livewire::test(ConnectionReport::class)->assertSee('Узорметалл');
});

it('does not resolve hidden web identities from a visible target or match users by display name', function () {
    $viewer = User::create(['username' => 'report-viewer', 'password' => 'test']);
    $viewer->deviceGroups()->attach($this->primary);
    $web = new Device(['rustdesk_id' => 'web-'.$this->staff->id]);
    reportPresentationSession($this->target, $web, $this->a, extra: ['from_name' => 'Reported name']);
    $this->actingAs($viewer);
    Livewire::test(ConnectionReport::class)
        ->assertViewHas('operators', fn ($rows) => isset($rows['device:'.$web->rustdesk_id]) && count($rows) === 1)
        ->assertSee('Reported name ('.$web->rustdesk_id.')')->assertDontSee('Светлана Иванова');
});

it('filters session attribution independently of device membership and limits device choices', function () {
    reportPresentationSession($this->target, $this->source, $this->a);
    reportPresentationSession($this->target, $this->source, $this->b);
    $component = Livewire::test(ConnectionReport::class)->set('workGroup', (string) $this->a->id)
        ->assertViewHas('count', 1)->assertViewHas('total', 3600)
        ->assertViewHas('targetDevices', fn ($rows) => $rows->pluck('rustdesk_id')->all() === [$this->target->rustdesk_id])
        ->set('target.rustdesk_id', $this->target->rustdesk_id)->set('workGroup', (string) $this->b->id)
        ->assertSet('target.rustdesk_id', $this->target->rustdesk_id)->assertViewHas('count', 1);
    $component->set('target.rustdesk_id', $this->other->rustdesk_id)->set('workGroup', (string) $this->a->id)
        ->assertSet('target.rustdesk_id', '')->assertViewHas('count', 1);
    $component->set('source.rustdesk_id', $this->source->rustdesk_id)
        ->set('source.device_group_id', (string) $this->b->id)->assertSet('source.rustdesk_id', '')
        ->assertViewHas('sourceDevices', fn ($rows) => $rows->pluck('rustdesk_id')->all() === [$this->other->rustdesk_id]);
});

it('retains historical choices after organization reassignment or deletion', function () {
    reportPresentationSession($this->target, $this->source, $this->a);
    $this->target->workGroups()->detach($this->a);
    $id = $this->a->id;
    $this->a->delete();
    Livewire::test(ConnectionReport::class)->set('workGroup', (string) $id)
        ->assertViewHas('count', 1)->assertViewHas('total', 3600)
        ->assertViewHas('targetDevices', fn ($rows) => $rows->pluck('rustdesk_id')->all() === [$this->target->rustdesk_id])
        ->assertSee('Узорметалл');
});

it('uses GMT plus three for both inclusive date boundaries and displayed timestamps', function () {
    foreach ([
        ['2026-10-31 20:30:00', '2026-10-31 21:30:00'],
        ['2026-11-01 20:30:00', '2026-11-01 21:30:00'],
        ['2026-11-01 21:00:00', '2026-11-01 22:00:00'],
        ['2026-10-31 20:00:00', '2026-10-31 21:00:00'],
        ['2026-11-01 20:00:00', null],
    ] as [$start, $end]) {
        reportPresentationSession($this->target, $this->source, $this->a, $start, $end);
    }
    Livewire::test(ConnectionReport::class)->set('dateFrom', '1.11.2026')->set('dateTo', '1.11.2026')
        ->assertViewHas('count', 3)->assertViewHas('total', 3600)->assertViewHas('unique', 3600)
        ->assertViewHas('active', 1)->assertSee('1.11.2026 23:30:00')->assertSee('GMT+3');
});

it('validates human date input, accepts old ISO state and uses the report timezone for month presets', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC'));
    Livewire::test(ConnectionReport::class)->assertSet('dateFrom', '1.11.2026')->assertSet('dateTo', '30.11.2026')
        ->set('dateTo', '31.11.2026')->assertHasErrors('dateTo')
        ->set('dateTo', '30.10.2026')->assertHasErrors('dateTo')
        ->set('dateTo', '30.11.2026')->assertHasNoErrors()
        ->set('dateFrom', '2026-11-01')->assertHasNoErrors()
        ->call('period', 'last')->assertSet('dateFrom', '1.10.2026')->assertSet('dateTo', '31.10.2026');
});

it('localizes the workbook and keeps dates, durations and counts typed and left aligned', function () {
    reportPresentationSession($this->target, $this->source, $this->a);
    $report = new ConnectionReport;
    $report->mount();
    $report->source = ['device_group_id' => (string) $this->support->id];
    $report->workGroup = (string) $this->a->id;
    $book = reportPresentationWorkbook($report);
    expect(array_keys($book))->toBe(['Сводка', 'Организации', 'По сотрудникам', 'Сеансы']);
    $summary = $book['Сводка']['cells'];
    $values = array_column($summary, 'value');
    expect($values)->toContain('Кто подключался', 'К кому подключались', 'Наша поддержка', 'Узорметалл', 'Все устройства', 'Все владельцы', 'GMT+3');
    foreach ($summary as $address => $cell) {
        if (str_starts_with($address, 'B')) {
            expect($cell['align'])->toBe('left');
        }
    }
    $sessions = $book['Сеансы']['cells'];
    expect($sessions['A2']['value'])->toBe('000101');
    expect($sessions['A2']['type'])->toBe('s');
    expect($sessions['B2']['value'])->toBe('Светлана Иванова');
    expect($sessions['C1']['value'])->toBe('К устройству');
    expect($sessions['C2']['value'])->toBe('Бухгалтерия (18446744073709551614)');
    expect($sessions['D2']['type'])->toBe('');
    expect((float) $sessions['D2']['value'])->toBe(SimpleXLSXGen::date2excel(2026, 11, 1, 9));
    expect($sessions['D2']['format'])->toBe('d.m.yyyy hh:mm:ss');
    expect($sessions['F2']['format'])->toBe('[h]:mm:ss');
    expect($sessions['G2']['value'])->toBe('Удалённое управление');
    expect($book['По сотрудникам']['cells']['A2']['value'])->toBe('Светлана Иванова');
    expect($book['По сотрудникам']['cells']['B2']['type'])->toBe('');
});

it('keeps formula-like text, links, markup, multiline notes and bare IDs literal', function () {
    $this->source->update(['user_id' => null]);
    $this->target->update(['alias' => null]);
    $note = "<f>1+1</f>\nhttps://example.test\n=SUM(A1:A2)";
    reportPresentationSession($this->target, $this->source, $this->a, extra: ['from_name' => '=1+1', 'note' => $note."\x01"]);
    $report = new ConnectionReport;
    $report->mount();
    $book = reportPresentationWorkbook($report);
    expect($book['Сеансы']['cells']['B2']['value'])->toBe('=1+1 (000101)');
    expect($book['Сеансы']['cells']['C2']['value'])->toBe('18446744073709551614');
    expect($book['Сеансы']['cells']['C2']['type'])->toBe('s');
    expect($book['Сеансы']['cells']['H2']['value'])->toBe($note);
    foreach ($book as $sheet) {
        expect($sheet['xml'])->not->toContain('<f>', '<hyperlink');
    }
});

it('keeps equal display names as separate operators and falls back to username', function () {
    $second = User::create(['username' => 'svetlana2', 'name' => $this->staff->name, 'password' => 'test']);
    $source = reportPresentationDevice('000102', $this->support, $second);
    reportPresentationSession($this->target, $this->source, $this->a);
    reportPresentationSession($this->target, $source, $this->a);
    Livewire::test(ConnectionReport::class)->assertViewHas('operators', fn ($rows) => count($rows) === 2)
        ->assertViewHas('unique', 7200)->assertSee('Светлана Иванова');
    $second->update(['name' => '   ']);
    Livewire::test(ConnectionReport::class)->assertViewHas('operators', fn ($rows) => in_array('svetlana2', array_column($rows, 'name'), true))
        ->assertSee('svetlana2');
});

it('preserves durations beyond twenty four hours as numeric Excel durations', function () {
    reportPresentationSession($this->target, $this->source, $this->a, '2026-11-01 00:00:00', '2026-11-05 04:00:00');
    Livewire::test(ConnectionReport::class)->assertViewHas('total', 360000)->assertSee('100:00:00');
    $report = new ConnectionReport;
    $report->mount();
    $book = reportPresentationWorkbook($report);
    expect(abs((float) $book['Сеансы']['cells']['F2']['value'] - 360000 / 86400))->toBeLessThan(0.00000001);
    expect($book['Сеансы']['cells']['F2']['format'])->toBe('[h]:mm:ss');
});

it('does not expose hidden devices or organizations through filters, summaries or export', function () {
    $role = Role::create(['name' => 'Scoped auditor', 'permissions' => ['audit' => 'r']]);
    $viewer = User::create(['username' => 'viewer', 'password' => 'test', 'role_id' => $role->id, 'locale' => 'ru']);
    $this->target->update(['user_id' => $viewer->id]);
    $secret = DeviceGroup::create(['name' => 'SECRET ORGANIZATION']);
    $hidden = reportPresentationDevice('SECRET-DEVICE', $secret, null, 'SECRET ALIAS');
    $hidden->workGroups()->attach($this->a);
    $viewer->deviceGroups()->attach($this->a);
    reportPresentationSession($hidden, $this->source, $secret);
    $this->actingAs($viewer);
    Livewire::test(ConnectionReport::class)->set('workGroup', (string) $secret->id)
        ->assertViewHas('count', 0)->assertDontSee('SECRET ORGANIZATION')->assertDontSee('SECRET-DEVICE')
        ->assertViewHas('targetDevices', fn ($rows) => $rows->isEmpty())->assertSee('Недоступно');
    $report = new ConnectionReport;
    $report->mount();
    $report->workGroup = (string) $secret->id;
    $book = reportPresentationWorkbook($report);
    expect(array_column($book['Сводка']['cells'], 'value'))->not->toContain('SECRET ORGANIZATION', 'SECRET ALIAS');
    expect(count($book['Сеансы']['cells']))->toBe(9);
});

it('shows explicit all filters and removes CSV from the report component', function () {
    expect(method_exists(ConnectionReport::class, 'export'))->toBeFalse();
    Livewire::test(ConnectionReport::class)->assertSee('Все организации')->assertSee('Все устройства')
        ->assertSee('Все владельцы')->assertSee('Все группы владельцев')
        ->assertDontSeeHtml('wire:click="export"')->assertSeeHtml('wire:click="exportXlsx"')
        ->assertDontSeeHtml('id="target-device_group_id"')
        ->assertSeeHtml('id="report-work-group"');
});

it('exports translated workbook labels for every supported locale', function () {
    foreach (array_keys(User::LOCALES) as $locale) {
        app()->setLocale($locale);
        $report = new ConnectionReport;
        $report->mount();
        $book = reportPresentationWorkbook($report);
        expect(array_keys($book))->toBe([__('report.sheet_summary'), __('report.sheet_organizations'), __('report.sheet_operators'), __('report.sheet_sessions')]);
        expect($book[__('report.sheet_summary')]['cells']['B1']['value'])->toBe(__('report.value'));
        expect($book[__('report.sheet_summary')]['cells']['B5']['value'])->toBe(__('report.all_organizations'));
    }
});
