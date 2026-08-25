<?php

namespace App\Livewire;

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use App\Models\Product;
use Livewire\Component;

class KitchenAvailability extends Component
{
    public string $search = '';

    public function boot(): void
    {
        abort_unless(EnsureKitchenAvailabilityAccess::allows(), 403);
    }

    public function setAvailability(int $productId, bool $available): void
    {
        $product = Product::query()->findOrFail($productId);

        if ($product->is_available !== $available) {
            $product->update(['is_available' => $available]);
        }
    }

    public function logout(): void
    {
        session()->forget(EnsureKitchenAvailabilityAccess::SESSION_KEY);
        session()->regenerate();
        session()->regenerateToken();

        $this->redirectRoute('kitchen.availability.login');
    }

    public function render()
    {
        $search = trim($this->search);

        return view('livewire.kitchen-availability', [
            'products' => Product::query()
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_available']),
        ])->layout('layouts.app');
    }
}
