<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\MergeCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Models\Customer;
use App\Services\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cartService) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Cart retrieved successfully.'
        );
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $data = $request->validated();

        $this->cartService->addItem(
            customer: $customer,
            productId: (int) $data['product_id'],
            quantity: (int) $data['quantity'],
            selectedColor: (string) ($data['selected_color'] ?? ''),
            selectedSize: (string) ($data['selected_size'] ?? ''),
        );

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Item added to cart.',
            201
        );
    }

    public function updateItem(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $this->cartService->updateItemQuantity(
            customer: $customer,
            cartItemId: $id,
            quantity: (int) $request->validated('quantity'),
        );

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Cart item updated.'
        );
    }

    public function removeItem(Request $request, int $id): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $this->cartService->removeItem($customer, $id);

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Cart item removed.'
        );
    }

    public function merge(MergeCartRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $this->cartService->mergeGuestItems($customer, $request->validated('items'));

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Guest cart merged successfully.'
        );
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user('customer');
        $this->cartService->applyCoupon($customer, (string) $validated['code']);

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Coupon applied successfully.'
        );
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $this->cartService->removeCoupon($customer);

        return ApiResponse::success(
            $this->cartService->summarize($customer),
            'Coupon removed.'
        );
    }
}
