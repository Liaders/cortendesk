<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsoleAudit;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceAliasController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $device = Device::visibleTo($request->user())->where('rustdesk_id', $id)->firstOrFail();

        return response()->json($this->state($request, $device));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alias' => 'present|nullable|string|max:255']);

        return DB::transaction(function () use ($request, $id, $data) {
            $device = Device::visibleTo($request->user())->where('rustdesk_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->consoleAllows('device', 'rw'), 403);
            $alias = $data['alias'] ?? null;
            if ((string) $device->alias !== (string) $alias) {
                // Do not modify the machine hostname or address-book entries.
                $device->update(['alias' => $alias]);
                ConsoleAudit::record('device.update', 'Updated device alias for '.$id, 'device', $id);
            }

            return response()->json(['saved' => true] + $this->state($request, $device));
        }, 5);
    }

    private function state(Request $request, Device $device): array
    {
        return [
            'api_version' => 2,
            'device_id' => (string) $device->rustdesk_id,
            'alias' => (string) $device->alias,
            'can_set_alias' => $request->user()->consoleAllows('device', 'rw'),
            'max_length' => 255,
        ];
    }
}
