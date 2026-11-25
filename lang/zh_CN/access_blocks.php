<?php

return [
    'title' => '访问封禁',
    'help' => '将此 IP 对所有服务封禁 24 小时，包括网页控制台、API、RustDesk 和 SSH。',
    'ip_warning' => 'IP：:ip。最近使用此地址的设备数：:count。全部将受影响。',
    'reason' => '原因',
    'public_ip_required' => '需要有效的公网 IP 地址。',
    'denied' => '此服务器的访问已被封禁。',
    'confirm_ip' => '将 :ip 对所有服务封禁 24 小时（包括网页控制台和 SSH）？如果您使用同一 IP，您也将无法访问。',
    'block_ip' => '封禁 IP 24 小时',
    'indefinite' => '直至手动解除',
    'revoke' => '解除封禁',
    'confirm_revoke' => '解除此封禁？',
    'inactive' => '已到期或已解除',
    'not_ready' => '主机防火墙服务近期未确认同步。',
    'pending' => '正在等待防火墙应用当前规则。',
];
