<?php

namespace App\Services;

use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\OrganizationImportBatch;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\OrganizationIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationImport
{
    public const MAX_ROWS = 10000;

    public static function authorize(User $actor): void
    {
        abort_unless($actor->consoleAllows('group', 'rw'), 403);
    }

    /** TXT is a tab-delimited table, including quoted fields and doubled quotes. */
    public static function parse(string $bytes): array
    {
        if (strlen($bytes) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['importFile' => __('organization_import.file_limit')]);
        }
        $encoding = 'UTF-8';
        if (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
            $encoding = 'UTF-16LE';
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            $encoding = 'UTF-16BE';
        } elseif (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
            $encoding = 'Windows-1251';
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        $line = 1;
        $first = true;
        try {
            while (! feof($stream)) {
                $offset = ftell($stream);
                $fields = fgetcsv($stream, 0, "\t", '"', '');
                $number = $line;
                $line += substr_count(substr($bytes, $offset, ftell($stream) - $offset), "\n");
                if ($fields === false || count($fields) === 1 && trim((string) $fields[0]) === '') {
                    continue;
                }
                $name = trim((string) ($fields[0] ?? ''));
                $inn = trim((string) ($fields[1] ?? ''));
                if ($first && in_array(OrganizationIdentity::nameKey($name), ['наименование', 'название', 'организация', 'name', 'nom', 'nombre', 'bezeichnung', 'név', 'nome', 'naam', 'nazwa', 'názov', '名称'], true)
                    && in_array(mb_strtolower($inn), ['инн', 'inn'], true)) {
                    $first = false;

                    continue;
                }
                $first = false;
                $rows[] = ['line' => $number, 'name' => $name, 'inn' => $inn, 'parse_error' => count($fields) !== 2 ? 'columns' : null];
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['importFile' => __('organization_import.file_limit')]);
                }
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['importFile' => __('organization_import.empty_file')]);
        }

        return ['rows' => $rows, 'encoding' => $encoding];
    }

    public static function plan(array $rows, bool $rename = false): array
    {
        $byInn = $byName = $input = $inputNames = [];
        foreach (DeviceGroup::query()->orderBy('id')->get(['id', 'name', 'inn', 'name_key']) as $group) {
            if ($group->inn) {
                $byInn[$group->inn] = $group;
            }
            $byName[$group->name_key ?? OrganizationIdentity::nameKey($group->name)][] = $group;
        }
        foreach ($rows as $row) {
            if ($row['inn'] !== '') {
                $input[$row['inn']][OrganizationIdentity::nameKey($row['name'])] = true;
                if (OrganizationIdentity::validInn($row['inn']) && ! ($row['parse_error'] ?? null)) {
                    $inputNames[OrganizationIdentity::nameKey($row['name'])][$row['inn']] = true;
                }
            }
        }
        $seen = [];
        $plan = [];
        foreach ($rows as $row) {
            $item = $row + ['target_id' => null, 'before_name' => null, 'before_inn' => null, 'action' => 'create', 'reason' => null];
            $key = OrganizationIdentity::nameKey($row['name']);
            $identity = $row['inn'] !== '' ? 'inn:'.$row['inn'] : 'name:'.$key;
            $error = $row['parse_error'] ?? null;
            if ($error === null && ($key === '' || mb_strlen($row['name']) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $row['name']))) {
                $error = 'name_invalid';
            }
            if ($error === null && ! OrganizationIdentity::validInn($row['inn'])) {
                $error = 'inn_invalid';
            }
            if ($error === null && $row['inn'] !== '' && count($input[$row['inn']]) > 1) {
                $error = 'duplicate_conflict';
            }
            if ($error === null && count($inputNames[$key] ?? []) > 1) {
                $error = 'inn_conflict';
            }
            if ($error !== null) {
                $item['action'] = 'error';
                $item['reason'] = $error;
            } elseif (isset($seen[$identity]) || ($row['inn'] === '' && count($inputNames[$key] ?? []) === 1)) {
                $item['action'] = 'skip';
                $item['reason'] = 'duplicate';
            } else {
                $match = $row['inn'] !== '' ? ($byInn[$row['inn']] ?? null) : null;
                $names = $byName[$key] ?? [];
                if ($match === null && count($names) > 1) {
                    $item['action'] = 'error';
                    $item['reason'] = 'ambiguous';
                } elseif ($match === null && count($names) === 1) {
                    $match = $names[0];
                    if ($row['inn'] !== '' && $match->inn !== null && $match->inn !== $row['inn']) {
                        $item['action'] = 'error';
                        $item['reason'] = 'inn_conflict';
                    }
                }
                if ($match !== null) {
                    $item['target_id'] = $match->id;
                    $item['before_name'] = $match->name;
                    $item['before_inn'] = $match->inn;
                    if ($item['action'] !== 'error') {
                        if ($row['inn'] !== '' && ! $match->inn) {
                            $item['action'] = 'update';
                        } elseif ($key !== OrganizationIdentity::nameKey($match->name)) {
                            $item['action'] = $rename ? 'update' : 'error';
                            $item['reason'] = $rename ? null : 'rename_review';
                        } else {
                            $item['action'] = 'skip';
                            $item['reason'] = 'unchanged';
                        }
                    }
                }
                $seen[$identity] = true;
            }
            $plan[] = $item;
        }

        return $plan;
    }

    public static function createBatch(User $actor, array $rows, bool $rename = false): OrganizationImportBatch
    {
        self::authorize($actor);
        OrganizationImportBatch::where('user_id', $actor->id)->whereNull('applied_at')->where('expires_at', '<', now())->delete();

        return OrganizationImportBatch::create([
            'id' => (string) Str::uuid(), 'user_id' => $actor->id, 'rows' => $rows,
            'preview' => self::plan($rows, $rename), 'expires_at' => now()->addHour(),
        ]);
    }

    public static function apply(User $actor, string $id, array $accessorIds, bool $rename, bool $skipErrors): array
    {
        self::authorize($actor);

        return DB::transaction(function () use ($actor, $id, $accessorIds, $rename, $skipErrors) {
            $batch = OrganizationImportBatch::whereKey($id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($batch->applied_at) {
                return $batch->result;
            }
            abort_if($batch->expires_at->isPast(), 409, __('organization_import.expired'));
            // Acquire the SQLite write lock before reading/matching the catalog.
            $batch->touch();
            $allowed = UserGroup::query()->when(! $actor->is_admin, fn ($q) => $q->whereIn('id', $actor->userGroupIds()))->pluck('id')->all();
            abort_unless(array_diff($accessorIds, $allowed) === [], 403);
            $plan = self::plan($batch->rows, $rename);
            if ($plan !== $batch->preview) {
                throw ValidationException::withMessages(['importFile' => __('organization_import.stale')]);
            }
            if (! $skipErrors && collect($plan)->contains('action', 'error')) {
                throw ValidationException::withMessages(['importFile' => __('organization_import.fix_errors')]);
            }
            $result = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
            foreach ($plan as $row) {
                $result[$row['action']]++;
                if ($row['action'] === 'create') {
                    $group = DeviceGroup::create(['name' => $row['name'], 'inn' => $row['inn'] ?: null]);
                    $group->syncAccessorUserGroups($accessorIds);
                } elseif ($row['action'] === 'update') {
                    $group = DeviceGroup::findOrFail($row['target_id']);
                    $values = ['inn' => $row['inn'] ?: $group->inn];
                    if ($rename) {
                        $values['name'] = $row['name'];
                    }
                    $group->update($values);
                } else {
                    continue;
                }
                ConsoleAudit::record('group.'.$row['action'], 'Imported organization '.$group->name, 'group', (string) $group->id);
            }
            $batch->update(['applied_at' => now(), 'result' => $result, 'rows' => [], 'preview' => []]);

            return $result;
        }, 5);
    }
}
