<?php

return [
    'title' => 'Bloqueo de acceso',
    'help' => 'Bloquea la IP durante 24 horas para RustDesk, la API del cliente y WebSocket. La administración web y SSH siguen disponibles.',
    'ip_warning' => 'IP: :ip. Dispositivos detectados: :count. Se bloquearán todos.',
    'reason' => 'Motivo',
    'public_ip_required' => 'Se necesita una dirección IP pública válida.',
    'denied' => 'El acceso a este servidor está bloqueado.',
    'confirm_ip' => '¿Bloquear :ip durante 24 horas para todos los dispositivos?',
    'block_ip' => 'Bloquear IP durante 24 horas',
    'indefinite' => 'Hasta desbloquear manualmente',
    'revoke' => 'Desbloquear',
    'confirm_revoke' => '¿Eliminar el bloqueo?',
    'inactive' => 'Caducado o revocado',
    'not_ready' => 'El servicio de firewall no ha confirmado la sincronización recientemente.',
    'pending' => 'Pendiente de aplicar las reglas actuales del firewall.',
];
