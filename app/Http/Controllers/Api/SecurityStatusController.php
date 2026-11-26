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
            'bans.*' => ['array:ip,until,since,repeated,country,provider'],
            'bans.*.ip' => ['required', 'ip'],
            'bans.*.until' => ['present', 'nullable', 'integer', 'min:0'],
            'bans.*.since' => ['nullable', 'integer', 'min:0'],
            'bans.*.repeated' => ['nullable', 'boolean'],
            'bans.*.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'bans.*.provider' => ['nullable', 'string', 'max:160'],
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
