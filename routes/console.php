<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('viva:reconcile-pending-payments')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('orders:anonymize-personal-data')
    ->daily()
    ->withoutOverlapping();
