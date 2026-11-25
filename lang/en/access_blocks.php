<?php

return [
    'title' => 'Access blocking',
    'help' => 'Device ID blocks do not identify a device after it changes its ID. IP blocks affect all devices at that address. Existing direct sessions are not terminated.',
    'ip_warning' => 'IP: :ip. Devices last seen at this address: :count. Blocking applies to all of them.',
    'reason' => 'Reason',
    'public_ip_required' => 'A valid public IP address is required.',
    'denied' => 'Access to this server is blocked.',
    'confirm_device' => 'Block this device ID?',
    'block_device' => 'Block device',
    'confirm_ip' => 'Block :ip for 24 hours? This affects every device at this address.',
    'block_ip' => 'Block IP for 24 hours',
    'indefinite' => 'Until manually revoked',
    'revoke' => 'Unblock',
    'confirm_revoke' => 'Remove this block?',
    'inactive' => 'Expired or revoked',
    'not_ready' => 'Blocking requires a compatible, connected ID and relay server.',
];
