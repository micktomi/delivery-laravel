<?php

return [
    'orders' => [
        // Set a retention period only after the business has selected one with
        // its legal/accounting advisers. Zero keeps the automated job disabled.
        'anonymization_days' => max(0, (int) env('ORDER_PII_ANONYMIZATION_DAYS', 0)),
    ],
];
