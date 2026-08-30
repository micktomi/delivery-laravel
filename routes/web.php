<?php

use App\Http\Controllers\VivaWalletController;
use App\Livewire\CheckoutPage;
use App\Livewire\DriverDashboard;
use App\Livewire\DriverLogin;
use App\Livewire\KitchenAvailability;
use App\Livewire\KitchenAvailabilityLogin;
use App\Livewire\MenuPage;
use App\Livewire\OrderBoard;
use App\Livewire\OrderTrackingPage;
use Illuminate\Support\Facades\Route;

Route::get('/', MenuPage::class)->name('menu');
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/order/{order}/track', OrderTrackingPage::class)->name('order.track');

Route::get('/payments/viva/{order}/start', [VivaWalletController::class, 'start'])->name('viva.start');
Route::get('/payments/viva/success', [VivaWalletController::class, 'success'])->name('viva.success');
Route::get('/payments/viva/failure', [VivaWalletController::class, 'failure'])->name('viva.failure');
Route::get('/payments/viva/webhook', [VivaWalletController::class, 'webhookVerify'])
    ->name('viva.webhook.verify');
Route::post('/payments/viva/webhook', [VivaWalletController::class, 'webhook'])
    ->name('viva.webhook');

Route::get('/kitchen', OrderBoard::class)->name('kitchen')->middleware('auth');
Route::get('/kitchen/availability/login', KitchenAvailabilityLogin::class)
    ->name('kitchen.availability.login');
Route::get('/kitchen/availability', KitchenAvailability::class)
    ->name('kitchen.availability')
    ->middleware('kitchen.availability');

Route::get('/driver/login', DriverLogin::class)->name('driver.login');
Route::middleware('auth:driver')->group(function (): void {
    Route::get('/driver', DriverDashboard::class)->name('driver.dashboard');
});
