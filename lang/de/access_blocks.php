<?php

return [
    'title' => 'Zugriffssperre',
    'help' => 'Sperrt die IP für 24 Stunden für alle Dienste, einschließlich Webkonsole, API, RustDesk und SSH.',
    'ip_warning' => 'IP: :ip. Zuletzt hier erkannte Geräte: :count. Alle sind betroffen.',
    'reason' => 'Grund',
    'public_ip_required' => 'Eine öffentliche IP-Adresse ist erforderlich.',
    'denied' => 'Der Zugriff auf diesen Server ist gesperrt.',
    'confirm_ip' => 'IP :ip für alle Dienste einschließlich Webkonsole und SSH 24 Stunden sperren? Bei gemeinsamer IP verlieren auch Sie den Zugriff.',
    'block_ip' => 'IP für 24 Stunden sperren',
    'indefinite' => 'Bis zur manuellen Aufhebung',
    'revoke' => 'Entsperren',
    'confirm_revoke' => 'Sperre aufheben?',
    'inactive' => 'Abgelaufen oder aufgehoben',
    'not_ready' => 'Der Firewall-Dienst hat die Synchronisierung nicht kürzlich bestätigt.',
    'pending' => 'Die aktuellen Firewall-Regeln werden noch angewendet.',
];
