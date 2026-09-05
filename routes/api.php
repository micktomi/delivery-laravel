<?php

use App\Http\Controllers\PrintJobController;
use App\Http\Middleware\AuthenticatePrintWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('printing')->middleware([AuthenticatePrintWorker::class, 'throttle:120,1,printing'])->group(function (): void {
    Route::post('next', [PrintJobController::class, 'next']);
    Route::post('{id}/accepted', [PrintJobController::class, 'accepted'])->whereUuid('id');
    Route::post('{id}/failed', [PrintJobController::class, 'failed'])->whereUuid('id');
});
