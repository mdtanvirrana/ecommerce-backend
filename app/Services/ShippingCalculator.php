<?php

namespace App\Services;

use App\Support\Money;

class ShippingCalculator
{
    public function calculate(string $subtotal): string
    {
        $normalized = Money::normalize($subtotal);

        if (Money::compare($normalized, '0.00') <= 0) {
            return '0.00';
        }

        $freeThreshold = Money::normalize((string) config('services.shipping.free_threshold', '250.00'));
        if (Money::compare($freeThreshold, '0.00') > 0 && Money::compare($normalized, $freeThreshold) >= 0) {
            return '0.00';
        }

        return Money::normalize((string) config('services.shipping.flat_rate', '15.00'));
    }
}
