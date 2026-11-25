<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FirewallPolicyController extends Controller
{
    public static function snapshot(): array
    {
        $blocks = AccessBlock::effective()->where('kind', 'ip')->orderBy('value')->get()
            ->groupBy('value')->map(fn ($rows) => $rows->max(fn ($row) => $row->expires_at->timestamp))->all();
        $blocks = (object) $blocks;

        return ['blocks' => $blocks, 'digest' => hash('sha256', json_encode($blocks, JSON_THROW_ON_ERROR))];
    }

    public function policy()
    {
        return response()->json(self::snapshot());
    }

    public function applied(Request $request)
    {
        $data = $request->validate(['digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        abort_unless(hash_equals(self::snapshot()['digest'], $data['digest']), 409);
        Cache::forever('statusdesk:firewall:applied_at', now()->toIso8601String());
        Cache::forever('statusdesk:firewall:digest', $data['digest']);

        return response()->noContent();
    }

    public function admission(Request $request)
    {
        return AccessBlock::denies('ip', AccessBlock::normalizeIp($request->ip()))
            ? response('', 403) : response()->noContent();
    }
}
