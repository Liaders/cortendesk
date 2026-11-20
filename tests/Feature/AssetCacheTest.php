<?php

use App\Support\Asset;

it('refreshes script URLs for a fork release without changing the client API version', function () {
    config(['cortendesk.api_version' => '1.23.0', 'cortendesk.asset_version' => '1.23.0-status.11']);
    $old = Asset::url('assets/js/group-picker.js');
    config(['cortendesk.asset_version' => '1.23.0-status.12']);
    $new = Asset::url('assets/js/group-picker.js');
    expect($old)->toEndWith('?v=1.23.0-status.11');
    expect($new)->not->toBe($old)->toEndWith('?v=1.23.0-status.12');
    expect(config('cortendesk.api_version'))->toBe('1.23.0');
});

it('retains upstream asset versioning when there is no distribution release metadata', function () {
    config(['cortendesk.api_version' => '1.23.0', 'cortendesk.asset_version' => null]);
    expect(Asset::url('assets/js/group-picker.js'))->toEndWith('?v=1.23.0');
});
