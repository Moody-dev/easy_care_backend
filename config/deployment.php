<?php

return [
    'supabase_url' => env('SUPABASE_URL'),
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'render' => (bool) env('RENDER', false),
    'platform' => env('HOSTING_PLATFORM', env('RENDER', false) ? 'render' : null),
    'persistent_root' => env('PERSISTENT_STORAGE_ROOT', env('RENDER_STORAGE_ROOT', '/var/data')),
];
