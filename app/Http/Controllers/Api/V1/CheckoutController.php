<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaymentService $paymentService,
    ) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $result = $this->checkoutService->checkout($customer, $request->validated());

        return ApiResponse::success([
            'order' => new OrderResource($result['order']),
            'payment' => new PaymentResource($result['payment']),
            'client_secret' => $result['client_secret'],
            'publishable_key' => $result['publishable_key'],
        ], 'Checkout initialized. Complete payment to confirm your order.', 201);
    }

    public function retryPayment(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if ($customer->cannot('pay', $order)) {
            return ApiResponse::error('You are not authorized to access this order.', 403);
        }

        $result = $this->checkoutService->retryPayment($customer, $order);

        return ApiResponse::success([
            'order' => new OrderResource($result['order']),
            'payment' => new PaymentResource($result['payment']),
            'client_secret' => $result['client_secret'],
            'publishable_key' => $result['publishable_key'],
        ], 'Payment retry initialized.');
    }

    /**
     * Server-side payment reconciliation endpoint (for delayed webhooks).
     * Never trusts client payment status — verifies directly with Stripe API.
     */
    public function confirmPayment(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if ($customer->cannot('pay', $order)) {
            return ApiResponse::error('You are not authorized to access this order.', 403);
        }

        $reconciled = $this->paymentService->reconcileOrderPayment($order);

        return ApiResponse::success(
            new OrderResource($reconciled),
            'Payment status verified with Stripe.'
        );
    }
}
