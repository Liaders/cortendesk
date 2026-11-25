<?php

namespace App\Http\Middleware;

use App\Models\AccessBlock;
use Closure;
use Illuminate\Http\Request;

class EnforceClientAccessBlocks
{
    public function handle(Request $request, Closure $next)
    {
        // Only authenticated infrastructure exchange is exempt from IP blocking.
        if ($request->is('api/server/*')) {
            return $next($request);
        }
        $denied = AccessBlock::denies('ip', AccessBlock::normalizeIp($request->ip()));
        if ($denied) {
            return response()->json(['error' => __('access_blocks.denied'), 'code' => 'access_blocked'], 403);
        }

        return $next($request);
    }
}
