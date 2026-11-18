<?php

return [
    'label' => 'Access to devices without an organization',
    'hint' => 'Shows devices with neither a primary nor an additional organization. Assigning an organization requires device management and access to that organization. Devices in other organizations remain restricted.',
    'scope' => 'Roles control console sections and actions. Ownership and organization grants control device visibility; the separate permission below can also expose devices without any organization. Address-book sharing rules remain unchanged.',
    'device_hint' => 'View opens Devices; Manage adds editing, approval, deletion and restoration. Visibility follows ownership, organization access and the separate permission for devices without an organization.',
];
