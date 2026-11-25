<?php

return [
    'title' => '访问封禁',
    'help' => '设备封禁仅识别其 ID。IP 封禁影响该地址下的所有设备。已建立的直连会话不会终止。',
    'ip_warning' => 'IP：:ip。最近使用此地址的设备数：:count。全部将受影响。',
    'reason' => '原因',
    'public_ip_required' => '需要有效的公网 IP 地址。',
    'denied' => '此服务器的访问已被封禁。',
    'confirm_device' => '封禁此设备 ID？',
    'block_device' => '封禁设备',
    'confirm_ip' => '将 :ip 下的所有设备封禁 24 小时？',
    'block_ip' => '封禁 IP 24 小时',
    'indefinite' => '直至手动解除',
    'revoke' => '解除封禁',
    'confirm_revoke' => '解除此封禁？',
    'inactive' => '已到期或已解除',
    'not_ready' => '需要兼容且已连接的 ID 和中继服务器。',
];
