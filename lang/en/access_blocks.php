<?php

return [
    'title' => 'Access blocking',
    'help' => 'Blocks this IP for 24 hours for ALL services, including the web console, API, RustDesk and SSH.',
    'ip_warning' => 'IP: :ip. Devices last seen at this address: :count. Blocking applies to all of them.',
    'reason' => 'Reason',
    'public_ip_required' => 'A valid public IP address is required.',
    'denied' => 'Access to this server is blocked.',
    'confirm_ip' => 'Block :ip for 24 hours? All devices at this address will lose access to the web console, RustDesk and SSH, including your own connection if you share this IP.',
    'block_ip' => 'Block IP for 24 hours',
    'indefinite' => 'Until manually revoked',
    'revoke' => 'Unblock',
    'confirm_revoke' => 'Remove this block?',
    'inactive' => 'Expired or revoked',
    'not_ready' => 'The host firewall service has not confirmed synchronization recently.',
    'pending' => 'Waiting for the firewall to apply the current rules.',
];
