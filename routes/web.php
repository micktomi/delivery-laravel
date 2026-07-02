<?php

use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Livewire\OrderBoard;
use App\Livewire\OrderTrackingPage;
use App\Livewire\KitchenHistory;
use Illuminate\Support\Facades\Route;

Route::get('/', MenuPage::class)->name('menu');
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/order/{order}/track', OrderTrackingPage::class)->name('order.track');
Route::get('/kitchen', OrderBoard::class)->name('kitchen')->middleware('auth');
Route::get('/kitchen/history', KitchenHistory::class)->name('kitchen.history')->middleware('auth');
