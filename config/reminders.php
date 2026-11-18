<?php

return [
    'enabled' => (bool) env('REMINDER_DELIVERY_ENABLED', true),
    'batch_size' => min(100, max(1, (int) env('REMINDER_BATCH_SIZE', 100))),
    'queue_connection' => 'reminders',
    'queue' => 'reminders',
    'unique_store' => 'database',
];
