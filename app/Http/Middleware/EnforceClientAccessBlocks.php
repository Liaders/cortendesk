<?php

namespace App\Http\Middleware;

use App\Models\AccessBlock;
use App\Models\ClientToken;
use Closure;
use Illuminate\Http\Request;

class EnforceClientAccessBlocks
{
    public function handle(Request $request, Closure $next)
    {
        // Infrastructure policy and administrative endpoints must remain reachable.
        if ($request->is('api/server/*', 'api/v1/*')) {
            return $next($request);
        }
        $id = $request->input('id');
        $info = $request->input('deviceInfo');
        $loginId = is_array($info) ? ($info['id'] ?? null) : null;
        $tokenId = $request->bearerToken()
            ? ClientToken::where('token', $request->bearerToken())->value('device_id') : null;
        $denied = AccessBlock::denies('ip', AccessBlock::normalizeIp($request->ip()));
        foreach ([$id, $loginId, $tokenId] as $candidate) {
            if (is_string($candidate) || is_int($candidate)) {
                $denied = $denied || AccessBlock::denies('device', (string) $candidate);
            }
        }
        if ($denied) {
            return response()->json(['error' => __('access_blocks.denied'), 'code' => 'access_blocked'], 403);
        }

        return $next($request);
    }
}
