<?php

return [
    // Manual kill switch. False always wins over the weekly schedule.
    'accepting_orders' => env('STORE_ACCEPTING_ORDERS', true),

    // JSON object keyed by lowercase English weekday. Each day contains one
    // or more ["HH:MM", "HH:MM"] intervals in Europe/Athens.
    'opening_hours' => env('STORE_OPENING_HOURS'),

    'closed_message' => env('STORE_CLOSED_MESSAGE'),

    'timezone' => 'Europe/Athens',
];
