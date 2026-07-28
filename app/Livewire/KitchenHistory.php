<?php

namespace App\Livewire;

use App\Models\Order;
use Livewire\Component;

class KitchenHistory extends Component
{
    public function render()
    {
        $today = today();

        // 1. Calculate top metrics for today
        $totalOrdersCount = Order::whereDate('created_at', $today)->count();

        $completedOrSentCount = Order::whereDate('created_at', $today)
            ->whereIn('status', ['completed', 'out'])
            ->count();

        $cancelledCount = Order::whereDate('created_at', $today)
            ->where('status', 'cancelled')
            ->count();

        $dailyRevenue = (float) Order::whereDate('created_at', $today)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        // 2. Fetch today's orders that are out, completed, or cancelled, eager loading items
        $orders = Order::whereDate('created_at', $today)
            ->whereIn('status', ['out', 'completed', 'cancelled'])
            ->with('items')
            ->orderBy('placed_at', 'desc')
            ->get();

        return view('livewire.kitchen-history', [
            'totalOrdersCount' => $totalOrdersCount,
            'completedOrSentCount' => $completedOrSentCount,
            'cancelledCount' => $cancelledCount,
            'dailyRevenue' => $dailyRevenue,
            'orders' => $orders,
        ])->layout('layouts.app');
    }
}
