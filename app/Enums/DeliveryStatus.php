<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Assigned => 'ΑΝΑΤΕΘΗΚΕ',
            self::PickedUp => 'ΠΑΡΑΛΗΦΘΗΚΕ',
            self::OutForDelivery => 'ΚΑΘ’ ΟΔΟΝ',
            self::Delivered => 'ΠΑΡΑΔΟΘΗΚΕ',
        };
    }
}
