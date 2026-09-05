<?php

return [
    // Shared bearer secret used by the shop worker to poll Laravel.
    'token' => env('PRINT_WORKER_TOKEN'),
];
