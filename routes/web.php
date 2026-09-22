<?php

use App\Http\Controllers\ManifestController;
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
Route::get('/manifest.webmanifest', [ManifestController::class, 'show'])->name('manifest');
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/order/{order}/track', OrderTrackingPage::class)->name('order.track');

Route::get('/payments/viva/{order}/start', [VivaWalletController::class, 'start'])->name('viva.start');
Route::get('/payments/viva/success', [VivaWalletController::class, 'success'])->name('viva.success');
Route::get('/payments/viva/failure', [VivaWalletController::class, 'failure'])->name('viva.failure');
Route::get('/payments/viva/webhook', [VivaWalletController::class, 'webhookVerify'])
    ->name('viva.webhook.verify');
// Unauthenticated, and every accepted payload costs one OAuth-authenticated
// Retrieve Transaction call to Viva. The ceiling sits far above Viva's real
// delivery and retry rate, so it only bites on abuse.
Route::post('/payments/viva/webhook', [VivaWalletController::class, 'webhook'])
    ->middleware('throttle:300,1')
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
