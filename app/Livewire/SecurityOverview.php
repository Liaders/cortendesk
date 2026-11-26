<?php

namespace App\Livewire;

use App\Models\AccessBlock;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class SecurityOverview extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function render()
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $snapshot = Cache::get('statusdesk:security:fail2ban');
        $fresh = is_array($snapshot) && ($snapshot['received_at'] ?? 0) > now()->subSeconds(90)->timestamp;

        return view('livewire.security-overview', [
            'snapshot' => is_array($snapshot) ? $snapshot : null,
            'fresh' => $fresh,
            'manual' => AccessBlock::with('creator')->latest('id')->limit(100)->get(),
        ]);
    }
}
