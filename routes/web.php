<?php

use App\Livewire\CheckoutPage;
use App\Livewire\DriverDashboard;
use App\Livewire\DriverLogin;
use App\Livewire\KitchenHistory;
use App\Livewire\MenuPage;
use App\Livewire\OrderBoard;
use App\Livewire\OrderTrackingPage;
use Illuminate\Support\Facades\Route;

Route::get('/', MenuPage::class)->name('menu');
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/order/{order}/track', OrderTrackingPage::class)->name('order.track');
Route::get('/kitchen', OrderBoard::class)->name('kitchen')->middleware('auth');
Route::get('/kitchen/history', KitchenHistory::class)->name('kitchen.history')->middleware('auth');

Route::get('/driver/login', DriverLogin::class)->name('driver.login');
Route::middleware('auth:driver')->group(function (): void {
    Route::get('/driver', DriverDashboard::class)->name('driver.dashboard');
});
