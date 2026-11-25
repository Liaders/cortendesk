<?php

return [
    'title' => 'Blocco degli accessi',
    'help' => 'Blocca l’IP per 24 ore per RustDesk, API client e WebSocket. La console web e SSH rimangono accessibili.',
    'ip_warning' => 'IP: :ip. Dispositivi rilevati: :count. Saranno coinvolti tutti.',
    'reason' => 'Motivo',
    'public_ip_required' => 'È richiesto un indirizzo IP pubblico valido.',
    'denied' => 'L’accesso a questo server è bloccato.',
    'confirm_ip' => 'Bloccare :ip per 24 ore per tutti i dispositivi?',
    'block_ip' => 'Blocca IP per 24 ore',
    'indefinite' => 'Fino alla revoca manuale',
    'revoke' => 'Sblocca',
    'confirm_revoke' => 'Rimuovere il blocco?',
    'inactive' => 'Scaduto o revocato',
    'not_ready' => 'Il servizio firewall non ha confermato di recente la sincronizzazione.',
];
