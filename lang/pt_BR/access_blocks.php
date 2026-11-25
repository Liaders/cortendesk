<?php

return [
    'title' => 'Bloqueio de acesso',
    'help' => 'Bloqueia o IP por 24 horas para todos os serviços, incluindo console web, API, RustDesk e SSH.',
    'ip_warning' => 'IP: :ip. Dispositivos detectados: :count. Todos serão afetados.',
    'reason' => 'Motivo',
    'public_ip_required' => 'É necessário um endereço IP público válido.',
    'denied' => 'O acesso a este servidor está bloqueado.',
    'confirm_ip' => 'Bloquear :ip por 24 horas para todos os serviços, incluindo console web e SSH? Se você compartilhar esse IP, também perderá o acesso.',
    'block_ip' => 'Bloquear IP por 24 horas',
    'indefinite' => 'Até a remoção manual',
    'revoke' => 'Desbloquear',
    'confirm_revoke' => 'Remover este bloqueio?',
    'inactive' => 'Expirado ou revogado',
    'not_ready' => 'O serviço de firewall não confirmou a sincronização recentemente.',
    'pending' => 'Aguardando a aplicação das regras atuais do firewall.',
];
