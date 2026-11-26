<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityStatusController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'state' => ['required', 'in:active,inactive,error'],
            'bans' => ['present', 'array', 'max:1000'],
            'bans.*' => ['array:ip,until'],
            'bans.*.ip' => ['required', 'ip'],
            'bans.*.until' => ['present', 'nullable', 'integer', 'min:0'],
            'events' => ['present', 'array', 'max:200'],
            'events.*' => ['array:ip,action,at'],
            'events.*.ip' => ['required', 'ip'],
            'events.*.action' => ['required', 'in:ban,unban'],
            'events.*.at' => ['required', 'integer', 'min:0'],
        ]);
        $data['received_at'] = now()->timestamp;
        Cache::put('statusdesk:security:fail2ban', $data, now()->addDay());

        return response()->noContent();
    }
}
