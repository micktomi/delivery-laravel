<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\StoreSetting;
use App\Observers\ProductObserver;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Product::observe(ProductObserver::class);

        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('SELECT 1');
        });

        // The same persisted singleton feeds the public layout and its menu
        // wordmark; no parallel configuration source is introduced.
        View::composer(['layouts.app', 'livewire.menu-page'], function (\Illuminate\View\View $view): void {
            $view->with('storeSettings', StoreSetting::current());
        });
    }
}
