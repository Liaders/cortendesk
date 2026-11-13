<?php

use App\Livewire\ConnectionReport;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Support\SessionTime;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    $this->actingAs($this->admin);
    Device::withoutEvents(function () {
        Device::create(['rustdesk_id' => 'target', 'uuid' => 'target', 'status' => 'active']);
        Device::create(['rustdesk_id' => 'source', 'uuid' => 'source', 'user_id' => $this->admin->id, 'status' => 'active']);
    });
});

it('counts only signed-in sessions and clips time to inclusive selected dates', function () {
    foreach ([
        ['source', '2020-01-01 23:00:00', '2020-01-02 01:00:00'],
        ['source', '2020-01-02 00:30:00', '2020-01-02 02:00:00'],
        ['source', '2020-01-02 23:00:00', '2020-01-03 01:00:00'],
        ['source', '2020-01-02 12:00:00', null],
        [null, '2020-01-02 12:00:00', '2020-01-02 13:00:00'],
        ['', '2020-01-02 12:00:00', '2020-01-02 13:00:00'],
    ] as [$from, $start, $end]) {
        $row = new AuditConnection(['rustdesk_id' => 'target', 'from_peer' => $from, 'closed_at' => $end]);
        $row->created_at = $start;
        $row->save();
    }
    Livewire::test(ConnectionReport::class)->set('dateFrom', '2020-01-02')->set('dateTo', '2020-01-02')
        ->assertViewHas('count', 4)->assertViewHas('active', 1)
        ->assertViewHas('total', 12600)->assertViewHas('unique', 10800)
        ->set('source.rustdesk_id', 'missing')->assertViewHas('count', 0);
});

it('shows a date field error without breaking the rendered page', function () {
    Livewire::test(ConnectionReport::class)->set('dateFrom', '2020-01-02')->set('dateTo', '2020-01-01')
        ->assertHasErrors('dateTo')->assertViewHas('count', 0)
        ->set('dateTo', '2020-01-03')->assertHasNoErrors();
});

it('limits CSV and XLSX to 10000 sessions and describes truncation in the workbook', function () {
    $row = ['rustdesk_id' => 'target', 'from_peer' => 'source', 'from_name' => '=1+1', 'note' => '<f>1+1</f>', 'created_at' => '2020-01-02 00:00:00', 'closed_at' => '2020-01-02 00:01:00'];
    for ($i = 0; $i < 20; $i++) {
        DB::table('audit_connections')->insert(array_fill(0, 500, $row));
    }
    DB::table('audit_connections')->insert($row);
    $report = new ConnectionReport;
    $report->dateFrom = '2020-01-02';
    $report->dateTo = '2020-01-02';
    expect($report->render()->getData()['count'])->toBe(10001);
    ob_start();
    $report->export()->sendContent();
    $csv = ob_get_clean();
    expect(substr_count($csv, "\n"))->toBe(10001);
    ob_start();
    $report->exportXlsx()->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'statusdesk-xlsx-');
    $zip = new ZipArchive;
    try {
        file_put_contents($path, $bytes);
        expect($zip->open($path))->toBeTrue();
        $details = new DOMDocument;
        expect($details->loadXML($zip->getFromName('xl/worksheets/sheet4.xml')))->toBeTrue();
        expect($details->getElementsByTagName('row')->length)->toBe(10001);
        expect($details->getElementsByTagName('f')->length)->toBe(0);
        $strings = $zip->getFromName('xl/sharedStrings.xml');
        expect($strings)->toContain('Export truncated', 'Yes: first 10000', '&lt;f&gt;1+1&lt;/f&gt;', '=1+1');
        expect($zip->getFromName('xl/styles.xml'))->toContain('[h]:mm:ss');
    } finally {
        $zip->close();
        unlink($path);
    }
});

it('keeps device visibility and rejects report exports after permission revocation', function () {
    AuditConnection::create(['rustdesk_id' => 'target', 'from_peer' => 'source', 'closed_at' => now()]);
    $role = Role::create(['name' => 'Auditor', 'permissions' => ['audit' => 'r']]);
    $viewer = User::create(['username' => 'viewer', 'password' => 'test', 'role_id' => $role->id]);
    $this->actingAs($viewer);
    Livewire::test(ConnectionReport::class)->assertViewHas('count', 0);
    $role->update(['permissions' => []]);
    $this->actingAs($viewer->fresh());
    foreach (['export', 'exportXlsx'] as $method) {
        try {
            (new ConnectionReport)->$method();
            $this->fail('An unauthorized export was accepted.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }
    }
});

it('keeps report translations complete and formats long elapsed time', function () {
    $english = require base_path('lang/en/report.php');
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/report.php');
        expect(array_keys($messages))->toBe(array_keys($english));
        expect($messages['help'])->toContain(':timezone');
    }
    expect(SessionTime::format(360001))->toBe('100:00:01');
    expect(SessionTime::withoutOverlaps(['a' => [[10, 20], [0, 15], [20, 30]], 'b' => [[0, 5]]]))->toBe(35);
});
