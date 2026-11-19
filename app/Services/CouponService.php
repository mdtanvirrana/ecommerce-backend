<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\User;
use App\Support\Money;

class CouponService
{
    /**
     * Validate a coupon code strictly on the backend and compute the exact decimal discount.
     *
     * @return array{coupon: Coupon, discount_amount: string}
     */
    public function validateAndCalculate(string $code, string $subtotal, ?User $customer = null): array
    {
        $normalizedCode = strtoupper(trim($code));
        $subtotalNormalized = Money::normalize($subtotal);

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        if ($coupon === null) {
            throw new DomainException('The coupon code is invalid.', 422, ['code' => ['The coupon code is invalid.']]);
        }

        if (! $coupon->is_active) {
            throw new DomainException('This coupon is no longer active.', 422, ['code' => ['This coupon is no longer active.']]);
        }

        $now = now();

        if ($coupon->starts_at !== null && $coupon->starts_at->isFuture()) {
            throw new DomainException('This coupon is not active yet.', 422, ['code' => ['This coupon is not active yet.']]);
        }

        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            throw new DomainException('This coupon has expired.', 422, ['code' => ['This coupon has expired.']]);
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw new DomainException('This coupon has reached its usage limit.', 422, ['code' => ['This coupon has reached its usage limit.']]);
        }

        if ($customer !== null && $coupon->per_customer_usage_limit !== null) {
            $customerUses = CouponUsage::query()
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $customer->id)
                ->count();

            if ($customerUses >= $coupon->per_customer_usage_limit) {
                throw new DomainException(
                    'You have already reached the maximum usage limit for this coupon.',
                    422,
                    ['code' => ['You have already reached the maximum usage limit for this coupon.']]
                );
            }
        }

        $minOrder = Money::normalize((string) $coupon->minimum_order_amount);
        if (Money::compare($subtotalNormalized, $minOrder) < 0) {
            throw new DomainException(
                "A minimum order amount of \${$minOrder} is required for this coupon.",
                422,
                ['code' => ["A minimum order amount of \${$minOrder} is required for this coupon."]]
            );
        }

        $discount = $this->calculateDiscountAmount($coupon, $subtotalNormalized);

        return [
            'coupon' => $coupon,
            'discount_amount' => $discount,
        ];
    }

    public function calculateDiscountAmount(Coupon $coupon, string $subtotal): string
    {
        $subtotalNormalized = Money::normalize($subtotal);

        if (Money::compare($subtotalNormalized, '0.00') <= 0) {
            return '0.00';
        }

        if ($coupon->type === CouponType::Percentage) {
            $discount = Money::percentage($subtotalNormalized, (string) $coupon->value);

            if ($coupon->maximum_discount_amount !== null && Money::compare((string) $coupon->maximum_discount_amount, '0.00') > 0) {
                $discount = Money::min($discount, (string) $coupon->maximum_discount_amount);
            }
        } else {
            $discount = Money::normalize((string) $coupon->value);
        }

        return Money::max('0.00', Money::min($discount, $subtotalNormalized));
    }
}
