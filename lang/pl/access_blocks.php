<?php

return [
    'title' => 'Blokowanie dostępu',
    'help' => 'Blokada rozpoznaje tylko ID urządzenia. Blokada IP obejmuje wszystkie urządzenia pod tym adresem. Istniejące sesje bezpośrednie trwają nadal.',
    'ip_warning' => 'IP: :ip. Wykryte urządzenia: :count. Blokada obejmie wszystkie.',
    'reason' => 'Powód',
    'public_ip_required' => 'Wymagany jest prawidłowy publiczny adres IP.',
    'denied' => 'Dostęp do tego serwera jest zablokowany.',
    'confirm_device' => 'Zablokować to ID?',
    'block_device' => 'Zablokuj urządzenie',
    'confirm_ip' => 'Zablokować :ip na 24 godziny dla wszystkich urządzeń?',
    'block_ip' => 'Zablokuj IP na 24 godziny',
    'indefinite' => 'Do ręcznego odblokowania',
    'revoke' => 'Odblokuj',
    'confirm_revoke' => 'Usunąć blokadę?',
    'inactive' => 'Wygasła lub odwołana',
    'not_ready' => 'Wymagane są zgodne i połączone serwery ID i relay.',
];
