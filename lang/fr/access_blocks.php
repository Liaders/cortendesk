<?php

return [
    'title' => 'Blocage des accès',
    'help' => 'Bloque cette IP pendant 24 heures pour RustDesk, l’API client et WebSocket. La console web et SSH restent accessibles.',
    'ip_warning' => 'IP : :ip. Appareils détectés : :count. Tous seront concernés.',
    'reason' => 'Motif',
    'public_ip_required' => 'Une adresse IP publique valide est nécessaire.',
    'denied' => 'L’accès à ce serveur est bloqué.',
    'confirm_ip' => 'Bloquer :ip pendant 24 heures pour tous les appareils ?',
    'block_ip' => 'Bloquer cette IP pendant 24 heures',
    'indefinite' => 'Jusqu’à révocation manuelle',
    'revoke' => 'Débloquer',
    'confirm_revoke' => 'Lever ce blocage ?',
    'inactive' => 'Expiré ou révoqué',
    'not_ready' => 'Le service de pare-feu n’a pas confirmé la synchronisation récemment.',
];
