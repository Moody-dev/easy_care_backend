<?php

return [
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'render' => (bool) env('RENDER', false),
    'persistent_root' => env('RENDER_STORAGE_ROOT', '/var/data'),
];
