<?php

use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Livewire\OrderBoard;
use Illuminate\Support\Facades\Route;

Route::get('/', MenuPage::class)->name('menu');
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/kitchen', OrderBoard::class)->name('kitchen')->middleware('auth');
