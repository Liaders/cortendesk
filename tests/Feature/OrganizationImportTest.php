<?php

use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\OrganizationImportBatch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\OrganizationImport;
use App\Support\OrganizationIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->actor = User::create(['username' => 'import-admin', 'password' => 'test', 'is_admin' => true, 'is_active' => true]);
    $this->team = UserGroup::create(['name' => 'Support']);
    $this->actor->groups()->attach($this->team);
    $this->actingAs($this->actor);
});

function organizationImportRows(string $text): array
{
    return OrganizationImport::parse($text)['rows'];
}

it('validates both INN lengths and checksums without losing leading zeros', function () {
    foreach (['7707083893', '500100732259', '0272014137', '', null] as $inn) {
        expect(OrganizationIdentity::validInn($inn))->toBeTrue();
    }
    foreach (['7707083894', '500100732258', '1,6441E+11', '21402969865', '272014137', '0000000000'] as $inn) {
        expect(OrganizationIdentity::validInn($inn))->toBeFalse();
    }
    Livewire::test(GroupList::class)->call('create', 'devices')->set('name', 'Customer')->set('inn', '7707083893')->call('save')->assertHasNoErrors();
    Livewire::test(GroupList::class)->call('create', 'devices')->set('name', 'Other')->set('inn', '7707083893')->call('save')->assertHasErrors('inn');
    Livewire::test(GroupList::class)->call('create', 'devices')->set('name', 'Bad')->set('inn', '7707083894')->call('save')->assertHasErrors('inn');
    Livewire::test(GroupList::class)->call('create', 'devices')->set('name', 'Leading zero')->set('inn', '0272014137')->call('save')->assertHasNoErrors();
    expect(DeviceGroup::where('name', 'Leading zero')->firstOrFail()->inn)->toBe('0272014137');
    Livewire::test(GroupList::class)->call('create', 'users')->set('name', 'User group')->set('inn', '7707083894')->call('save')->assertHasNoErrors();
});

it('parses tabular TXT quoting, empty values, BOM and supported encodings', function () {
    $text = "Наименование\tИНН\r\n\"АБ \"\"Тест\"\"\"\t7707083893\r\nБез ИНН\t\r\n\r\n";
    foreach ([$text, "\xEF\xBB\xBF".$text, mb_convert_encoding($text, 'Windows-1251', 'UTF-8'), "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')] as $bytes) {
        $rows = organizationImportRows($bytes);
        expect($rows)->toHaveCount(2);
        expect($rows[0]['name'])->toBe('АБ "Тест"');
        expect($rows[0]['inn'])->toBe('7707083893');
        expect($rows[0]['line'])->toBe(2);
        expect($rows[1]['inn'])->toBe('');
        expect($rows[0]['parse_error'])->toBeNull();
    }
    expect(OrganizationImport::plan(organizationImportRows("Wrong;7707083893\n"))[0]['reason'])->toBe('columns');
    expect(fn () => organizationImportRows(''))->toThrow(ValidationException::class);
    expect(fn () => organizationImportRows(str_repeat('x', 2 * 1024 * 1024 + 1)))->toThrow(ValidationException::class);
});

it('matches by INN and normalized exact name while preserving device links, grants, notes and session history', function () {
    $group = DeviceGroup::create(['name' => '  Альфа   ООО ', 'note' => 'Keep note']);
    $group->syncAccessorUserGroups([$this->team->id]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'target', 'uuid' => 'target', 'status' => 'active', 'device_group_id' => $group->id]));
    $device->workGroups()->attach($group);
    $audit = new AuditConnection(['rustdesk_id' => 'target', 'from_peer' => 'source']);
    $audit->forceFill(['work_group_id' => $group->id, 'work_group_name' => 'Historical name', 'work_group_status' => 'selected'])->save();
    $batch = OrganizationImport::createBatch($this->actor, organizationImportRows("альфа ооо\t7707083893\nNew customer\t500100732259\n"));
    expect(array_column($batch->preview, 'action'))->toBe(['update', 'create']);
    $result = OrganizationImport::apply($this->actor, $batch->id, [], false, false);
    expect($result)->toBe(['create' => 1, 'update' => 1, 'skip' => 0, 'error' => 0]);
    expect($group->fresh()->inn)->toBe('7707083893');
    expect($group->fresh()->name)->toBe('  Альфа   ООО ');
    expect($group->fresh()->note)->toBe('Keep note');
    expect($group->fresh()->accessorUserGroupIds())->toBe([$this->team->id]);
    expect($device->fresh()->device_group_id)->toBe($group->id);
    expect($device->fresh()->workGroups->pluck('id')->all())->toBe([$group->id]);
    expect($audit->fresh()->work_group_name)->toBe('Historical name');
    expect(OrganizationImport::apply($this->actor, $batch->id, [], false, false))->toBe($result);
    expect(DeviceGroup::count())->toBe(2);
    expect($batch->fresh()->rows)->toBe([]);
});

it('requires explicit renaming and never clears an existing INN from an empty input', function () {
    $group = DeviceGroup::create(['name' => 'Old name', 'inn' => '7707083893']);
    $rows = organizationImportRows("New name\t7707083893\n");
    expect(OrganizationImport::plan($rows)[0]['reason'])->toBe('rename_review');
    $batch = OrganizationImport::createBatch($this->actor, $rows, true);
    OrganizationImport::apply($this->actor, $batch->id, [], true, false);
    expect($group->fresh()->name)->toBe('New name');
    $batch = OrganizationImport::createBatch($this->actor, organizationImportRows("NEW NAME\t\n"));
    expect($batch->preview[0]['action'])->toBe('skip');
    OrganizationImport::apply($this->actor, $batch->id, [], false, false);
    expect($group->fresh()->inn)->toBe('7707083893');
});

it('flags ambiguous names, different INNs, scientific notation and conflicting input duplicates', function () {
    DeviceGroup::create(['name' => 'Same', 'inn' => '7707083893']);
    DeviceGroup::create(['name' => 'Ambiguous']);
    DeviceGroup::create(['name' => 'AMBIGUOUS']);
    $rows = organizationImportRows("Same\t500100732259\nAmbiguous\t\nBad\t1,6441E+11\nDuplicate A\t7733573894\nDuplicate B\t7733573894\nPlain\t\nPlain\t\n");
    $plan = OrganizationImport::plan($rows);
    expect(array_column($plan, 'reason'))->toBe(['inn_conflict', 'ambiguous', 'inn_invalid', 'duplicate_conflict', 'duplicate_conflict', null, 'duplicate']);
    $batch = OrganizationImport::createBatch($this->actor, $rows);
    expect(fn () => OrganizationImport::apply($this->actor, $batch->id, [], false, false))->toThrow(ValidationException::class);
    expect(DeviceGroup::count())->toBe(3);
    $result = OrganizationImport::apply($this->actor, $batch->id, [], false, true);
    expect($result)->toBe(['create' => 1, 'update' => 0, 'skip' => 1, 'error' => 5]);
});

it('previews without mutations, corrects a row and applies only from the explicit action', function () {
    $file = UploadedFile::fake()->createWithContent('organizations.txt', "Наименование\tИНН\nCustomer\t7707083894\n");
    $component = Livewire::test(GroupList::class)->call('openImport')->set('importFile', $file)->call('previewImport')->assertHasNoErrors()
        ->assertViewHas('importCounts', fn ($counts) => $counts['error'] === 1);
    expect(DeviceGroup::count())->toBe(0);
    $component->call('editImportRow', 0)->set('importRowInn', '7707083893')->call('saveImportRow')->assertHasNoErrors()
        ->assertViewHas('importCounts', fn ($counts) => $counts['create'] === 1)->call('applyImport')->assertHasNoErrors()
        ->assertViewHas('importResult', fn ($result) => $result['create'] === 1);
    expect(DeviceGroup::firstOrFail()->accessorUserGroupIds())->toBe([$this->team->id]);
    $component->call('applyImport')->assertHasNoErrors();
    expect(DeviceGroup::count())->toBe(1);
    Livewire::test(GroupList::class)->call('openImport')->set('importFile', UploadedFile::fake()->createWithContent('organizations.csv', "Name;INN\nTest;7707083893"))->call('previewImport')->assertHasErrors('importFile');
});

it('rejects stale previews and applies no partial changes', function () {
    $rows = organizationImportRows("Create first\t\nExisting\t7707083893\n");
    $batch = OrganizationImport::createBatch($this->actor, $rows);
    DeviceGroup::create(['name' => 'Existing', 'inn' => '7707083893']);
    expect(fn () => OrganizationImport::apply($this->actor, $batch->id, [], false, false))->toThrow(ValidationException::class);
    expect(DeviceGroup::where('name', 'Create first')->exists())->toBeFalse();
    expect($batch->fresh()->applied_at)->toBeNull();
    $batch->update(['preview' => OrganizationImport::plan($rows)]);
    expect(OrganizationImport::apply($this->actor, $batch->id, [], false, false)['create'])->toBe(1);
});

it('prefers the complete incoming identity and flags every conflicting same-name row', function () {
    $plan = OrganizationImport::plan(organizationImportRows("Customer\t\nCUSTOMER\t7707083893\n"));
    expect(array_column($plan, 'action'))->toBe(['skip', 'create']);
    $plan = OrganizationImport::plan(organizationImportRows("Customer\t7707083893\nCUSTOMER\t500100732259\n"));
    expect(array_column($plan, 'action'))->toBe(['error', 'error']);
    $plan = OrganizationImport::plan(organizationImportRows("Customer\t\nCUSTOMER\t1,6441E+11\n"));
    expect(array_column($plan, 'action'))->toBe(['create', 'error']);
});

it('preserves old client receipt hashes and supports optional client INN creation and search', function () {
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'visible', 'uuid' => 'visible', 'status' => 'active']));
    $token = ClientToken::issue($this->actor, ['id' => 'source']);
    $old = DeviceGroup::create(['name' => 'Previous result']);
    $requestId = (string) Str::uuid();
    $payload = ['device_id' => 'visible', 'name' => 'Previous result', 'note' => null, 'user_group_ids' => []];
    DB::table('client_organization_requests')->insert([
        'user_id' => $this->actor->id, 'operation' => 'create', 'request_id' => $requestId,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now(),
        'organization_id' => $old->id, 'response' => json_encode(['organization' => ['id' => $old->id, 'name' => $old->name], 'user_group_ids' => []]),
    ]);
    Auth::forgetGuards();
    $this->withToken($token->token)->postJson('/api/statusdesk/organizations', ['request_id' => $requestId] + $payload)->assertOk()->assertJsonPath('organization.id', $old->id);
    $new = ['device_id' => 'visible', 'request_id' => (string) Str::uuid(), 'name' => 'New result', 'inn' => '7707083893', 'user_group_ids' => []];
    Auth::forgetGuards();
    $id = $this->withToken($token->token)->postJson('/api/statusdesk/organizations', $new)->assertOk()->json('organization.id');
    Auth::forgetGuards();
    $this->withToken($token->token)->postJson('/api/statusdesk/organizations', $new)->assertOk()->assertJsonPath('organization.id', $id);
    Auth::forgetGuards();
    $this->withToken($token->token)->getJson('/api/statusdesk/devices/visible/organization-options?q=770708')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.inn', '7707083893');
});

it('backfills only derived search fields and preserves original data through migration', function () {
    $group = DeviceGroup::create(['name' => 'Старая организация', 'note' => 'Preserved']);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'legacy', 'uuid' => 'legacy', 'status' => 'active', 'device_group_id' => $group->id]));
    $device->workGroups()->attach($group);
    $group->syncAccessorUserGroups([$this->team->id]);
    $before = DB::table('device_groups')->where('id', $group->id)->first(['id', 'name', 'note', 'created_at', 'updated_at']);
    $migration = require database_path('migrations/2026_11_17_000001_add_organization_inn_and_imports.php');
    $migration->down();
    $migration->up();
    expect(DB::table('device_groups')->where('id', $group->id)->first(['id', 'name', 'note', 'created_at', 'updated_at']))->toEqual($before);
    expect($group->fresh()->name_key)->toBe('старая организация');
    expect($group->fresh()->inn)->toBeNull();
    expect($device->fresh()->device_group_id)->toBe($group->id);
    expect($device->fresh()->workGroups->pluck('id')->all())->toBe([$group->id]);
    expect($group->fresh()->accessorUserGroupIds())->toBe([$this->team->id]);
});

it('never exposes inaccessible organizations through remote device-editor search', function () {
    $role = Role::create(['name' => 'Device editor', 'permissions' => ['device' => 'rw']]);
    $viewer = User::create(['username' => 'device-editor', 'password' => 'test', 'role_id' => $role->id]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'owned', 'uuid' => 'owned', 'status' => 'active', 'user_id' => $viewer->id]));
    DeviceGroup::create(['name' => 'Secret organization', 'inn' => '7707083893']);
    $this->actingAs($viewer);
    Livewire::test(DeviceList::class)->call('edit', $device->id)->call('searchEditorGroups', 'Secret')->assertReturned(['data' => [], 'has_more' => false]);
});

it('rolls back all changes and audit entries if an insertion fails', function () {
    $batch = OrganizationImport::createBatch($this->actor, organizationImportRows("First\t\nFail\t\n"));
    DB::unprepared("CREATE TRIGGER reject_import BEFORE INSERT ON device_groups WHEN NEW.name = 'Fail' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    try {
        expect(fn () => OrganizationImport::apply($this->actor, $batch->id, [], false, false))->toThrow(QueryException::class);
        expect(DeviceGroup::count())->toBe(0);
        expect(ConsoleAudit::where('action', 'group.create')->count())->toBe(0);
        expect($batch->fresh()->applied_at)->toBeNull();
    } finally {
        DB::unprepared('DROP TRIGGER reject_import');
    }
});

it('checks current permissions, draft ownership, expiry and grant scope', function () {
    $role = Role::create(['name' => 'Delegate', 'permissions' => ['group' => 'rw']]);
    $delegate = User::create(['username' => 'delegate', 'password' => 'test', 'role_id' => $role->id]);
    $own = UserGroup::create(['name' => 'Own team']);
    $delegate->groups()->attach($own);
    $batch = OrganizationImport::createBatch($delegate, organizationImportRows("New\t\n"));
    try {
        OrganizationImport::apply($delegate, $batch->id, [$this->team->id], false, false);
        $this->fail('Unauthorized grant accepted');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    $this->actingAs($delegate);
    OrganizationImport::apply($delegate, $batch->id, [$own->id], false, false);
    expect(DeviceGroup::firstOrFail()->accessorUserGroupIds())->toBe([$own->id]);
    $role->update(['permissions' => ['group' => 'r']]);
    $this->actingAs($delegate->fresh());
    Livewire::test(GroupList::class)->call('openImport')->assertForbidden();
    $this->actingAs($this->actor);
    $expired = OrganizationImport::createBatch($this->actor, organizationImportRows("Expired\t\n"));
    $expired->update(['expires_at' => now()->subSecond()]);
    try {
        OrganizationImport::apply($this->actor, $expired->id, [], false, false);
        $this->fail('Expired draft accepted');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    expect(fn () => OrganizationImport::apply($this->actor, $batch->id, [], false, false))->toThrow(ModelNotFoundException::class);
});

it('paginates thousands of organizations, searches Unicode names and INNs, and preserves position after editing', function () {
    $rows = [];
    for ($i = 0; $i < 1200; $i++) {
        $name = 'Компания '.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
        $rows[] = ['name' => $name, 'name_key' => OrganizationIdentity::nameKey($name), 'inn' => $i === 1199 ? '7707083893' : null];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('device_groups')->insert($chunk);
    }
    $component = Livewire::test(GroupList::class)->assertViewHas('deviceGroups', fn ($groups) => $groups->total() === 1200 && $groups->count() === 50)
        ->set('search', 'КОМПАНИЯ 1199')->assertViewHas('deviceGroups', fn ($groups) => $groups->total() === 1)
        ->set('search', '7707083893')->assertViewHas('deviceGroups', fn ($groups) => $groups->total() === 1)
        ->set('search', '')->call('gotoPage', 2, 'organizationsPage');
    $id = DeviceGroup::where('name', 'Компания 0051')->value('id');
    $component->call('edit', 'devices', $id)->set('note', 'Updated')->call('save')->assertHasNoErrors()
        ->assertSet('paginators.organizationsPage', 2);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'own', 'uuid' => 'own', 'status' => 'active']));
    Livewire::test(DeviceList::class)->call('edit', $device->id)
        ->assertViewHas('editorGroups', fn ($groups) => $groups->count() === 50)
        ->call('searchEditorGroups', 'КОМПАНИЯ 1199')->assertReturned(['data' => [['id' => DeviceGroup::where('inn', '7707083893')->value('id'), 'name' => 'Компания 1199', 'inn' => '7707083893']], 'has_more' => false]);
});

it('keeps import translations complete in all original languages', function () {
    $english = require base_path('lang/en/organization_import.php');
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/organization_import.php');
        expect(array_keys($messages))->toBe(array_keys($english));
        foreach ($messages as $message) {
            expect(trim($message))->not->toBe('');
        }
        expect($messages['completed'])->toContain(':create', ':update', ':skip', ':error');
    }
});

it('prunes expired import drafts and old receipts without deleting organizations', function () {
    $organization = DeviceGroup::create(['name' => 'Retained organization']);
    $rows = organizationImportRows("New\t\n");
    $active = OrganizationImport::createBatch($this->actor, $rows);
    $expired = OrganizationImport::createBatch($this->actor, $rows);
    $recent = OrganizationImport::createBatch($this->actor, $rows);
    $old = OrganizationImport::createBatch($this->actor, $rows);
    $expired->update(['expires_at' => now()->subMinute()]);
    $recent->update(['applied_at' => now()->subDay(), 'expires_at' => now()->subDay()]);
    $old->update(['applied_at' => now()->subDays(31)]);

    $this->artisan('statusdesk:prune-organization-imports')->assertSuccessful();

    expect(OrganizationImportBatch::whereKey($active->id)->exists())->toBeTrue();
    expect(OrganizationImportBatch::whereKey($recent->id)->exists())->toBeTrue();
    expect(OrganizationImportBatch::whereKey($expired->id)->exists())->toBeFalse();
    expect(OrganizationImportBatch::whereKey($old->id)->exists())->toBeFalse();
    expect($organization->fresh()->name)->toBe('Retained organization');
});
