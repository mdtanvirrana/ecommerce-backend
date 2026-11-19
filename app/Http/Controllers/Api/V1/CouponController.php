<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CartService;
use App\Services\CouponService;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(
        private readonly CouponService $couponService,
        private readonly CartService $cartService,
    ) {}

    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var Customer|null $customer */
        $customer = $request->user('customer');

        if ($customer !== null) {
            $cart = $this->cartService->getOrCreateCart($customer)->load('items.product');
            $subtotal = $cart->items->isNotEmpty()
                ? $this->cartService->computeRawSubtotal($cart)
                : Money::normalize((string) ($data['subtotal'] ?? '0'));
        } else {
            $subtotal = Money::normalize((string) ($data['subtotal'] ?? '0'));
        }

        $result = $this->couponService->validateAndCalculate((string) $data['code'], $subtotal, $customer);
        $coupon = $result['coupon'];

        return ApiResponse::success([
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'value' => (string) $coupon->value,
            'subtotal' => $subtotal,
            'discount_amount' => $result['discount_amount'],
            'final_subtotal' => Money::max('0.00', Money::sub($subtotal, $result['discount_amount'])),
        ], 'Coupon is valid.');
    }
}
