<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum order amount
    |--------------------------------------------------------------------------
    |
    | The smallest cart subtotal (before delivery fee, payment surcharges or
    | any coupon discount) an order is allowed to place with.
    |
    */

    'minimum_order_amount' => (float) env('MINIMUM_ORDER_AMOUNT', 5.00),

];
