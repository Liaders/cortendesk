<?php

use App\Models\AccessBlock;
use Illuminate\Contracts\Console\Kernel;

// Isolated CI container only: exercise nginx admission, not just Laravel routes.
if (getenv('STATUSDESK_ISOLATED_SMOKE') !== '1') {
    throw new RuntimeException('An isolated container is required.');
}
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$block = AccessBlock::create(['kind' => 'ip', 'value' => '8.8.8.8', 'reason' => 'Isolated CI probe', 'expires_at' => now()->addDay()]);
try {
    $status = function (string $path, string $ip): int {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5,
            'header' => 'X-Forwarded-For: '.$ip."\r\n"]]);
        file_get_contents('http://127.0.0.1:8080'.$path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);

        return (int) $match[1];
    };
    foreach (['/ws/id', '/ws/relay'] as $path) {
        if ($status($path, '8.8.8.8') !== 403) {
            throw new RuntimeException('Blocked WebSocket request was admitted: '.$path);
        }
        if ($status($path, '8.8.4.4') === 403) {
            throw new RuntimeException('Unrelated WebSocket request was denied: '.$path);
        }
    }
    foreach (['/login', '/devices', '/assets/js/group-picker.js'] as $path) {
        if ($status($path, '8.8.8.8') !== 403) {
            throw new RuntimeException('Blocked web request was admitted: '.$path);
        }
    }
    if ($status('/login', '8.8.4.4') !== 200) {
        throw new RuntimeException('Unrelated web client was denied.');
    }
    echo "PASS nginx IP admission: WebSocket, web login, devices and static files blocked; unrelated web clients allowed.\n";
} finally {
    $block->delete();
}
