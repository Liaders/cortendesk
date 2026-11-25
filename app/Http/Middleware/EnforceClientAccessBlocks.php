<?php

namespace App\Http\Middleware;

use App\Models\AccessBlock;
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
        $denied = AccessBlock::denies('ip', AccessBlock::normalizeIp($request->ip()));
        if ($denied) {
            return response()->json(['error' => __('access_blocks.denied'), 'code' => 'access_blocked'], 403);
        }

        return $next($request);
    }
}
