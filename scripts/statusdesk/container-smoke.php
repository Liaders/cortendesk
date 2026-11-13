<?php

use App\Livewire\ConnectionReport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$admin = User::where('is_admin', true)->firstOrFail();
auth()->setUser($admin);
$request = Request::create(config('app.url').'/logs/connection-report', 'GET');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
if ($response->getStatusCode() !== 200 || ! str_contains($response->getContent(), 'wire:snapshot')) {
    throw new RuntimeException('The authenticated report did not render.');
}
$report = new ConnectionReport;
$report->mount();
ob_start();
$report->export()->sendContent();
$csv = ob_get_clean();
ob_start();
$report->exportXlsx()->sendContent();
$xlsx = ob_get_clean();
if (! str_contains($csv, 'From ID') || ! str_starts_with($xlsx, 'PK')) {
    throw new RuntimeException('A report export failed.');
}
echo "Authenticated report and CSV/XLSX checks passed.\n";
