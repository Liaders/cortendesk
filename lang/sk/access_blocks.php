<?php

return [
    'title' => 'Blokovanie prístupu',
    'help' => 'Blokuje IP na 24 hodín pre všetky služby vrátane webovej konzoly, API, RustDesk a SSH.',
    'ip_warning' => 'IP: :ip. Zistené zariadenia: :count. Blokovanie sa týka všetkých.',
    'reason' => 'Dôvod',
    'public_ip_required' => 'Vyžaduje sa platná verejná IP adresa.',
    'denied' => 'Prístup k tomuto serveru je zablokovaný.',
    'confirm_ip' => 'Zablokovať :ip na 24 hodín pre všetky služby vrátane webovej konzoly a SSH? Ak zdieľate túto IP, stratíte prístup aj vy.',
    'block_ip' => 'Zablokovať IP na 24 hodín',
    'indefinite' => 'Do ručného zrušenia',
    'revoke' => 'Odblokovať',
    'confirm_revoke' => 'Zrušiť blokovanie?',
    'inactive' => 'Platnosť uplynula alebo bolo zrušené',
    'not_ready' => 'Služba firewallu nedávno nepotvrdila synchronizáciu.',
    'pending' => 'Čaká sa na použitie aktuálnych pravidiel firewallu.',
];
